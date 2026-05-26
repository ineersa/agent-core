<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolExecutionContextInterface;
use Symfony\Component\Process\Process;

/**
 * Shared foreground process runner with timeout, cancellation detection,
 * optional observer/decision hook, and output cap integration point.
 *
 * Owns:
 * - Starting Symfony Process instances
 * - Creating process groups for tree safety
 * - Registering ForegroundTool records in ToolProcessRegistry
 * - Timeout enforcement via ToolProcessTerminator
 * - Cancellation detection (token check after exit)
 * - An observer/decision hook so future bash can request
 *   Continue|Terminate|DetachToBackground without duplicating lifecycle
 * - Unregistration in finally unless detach transfers ownership
 *
 * Does NOT own:
 * - Cancellation signal emission (ownership: controller/runtime)
 * - Background process management (ownership: BackgroundProcessManager)
 * - Per-tool argument parsing or result formatting
 */
final class ForegroundProcessRunner
{
    /**
     * Decision from the observer/decision hook.
     */
    public const string DECISION_CONTINUE = 'continue';
    public const string DECISION_TERMINATE = 'terminate';
    public const string DECISION_DETACH_BACKGROUND = 'detach_to_background';

    /**
     * @var (callable(Process, ToolExecutionContextInterface, int): string)|null
     */
    private $decisionHook;

    public function __construct(
        private readonly ToolProcessRegistry $registry,
        private readonly ToolProcessTerminator $terminator,
        private readonly ?ToolExecutionContextInterface $fallbackContext = null,
        ?callable $decisionHook = null,
    ) {
        $this->decisionHook = $decisionHook;
    }

    /**
     * Run a process, register it, wait for completion, and unregister.
     *
     * The process is registered as a foreground tool before waiting. After
     * completion, the run context is checked for cancellation and the
     * record is unregistered unless the decision hook returns DETACH_BACKGROUND.
     *
     * @param ToolExecutionContextInterface $context Execution context for cancellation/timeout metadata
     */
    public function run(ProcessSpec $spec, ToolExecutionContextInterface $context): ProcessRunResult
    {
        $process = new Process(
            command: $spec->command,
            cwd: $spec->cwd,
            env: $spec->env,
            timeout: null, // We manage timeout ourselves via ToolProcessTerminator + wall-clock tracking
        );

        if ($spec->createProcessGroup && \function_exists('posix_setsid')) {
            $process->setOptions(['create_new_console' => true]);
        }

        $process->start();

        $pid = $process->getPid();
        if (null === $pid) {
            throw new \RuntimeException('Failed to start process — no PID assigned.');
        }

        $pgid = null;
        if ($spec->createProcessGroup && \function_exists('posix_getpgid')) {
            $pgid = @posix_getpgid($pid);
            if (false === $pgid) {
                $pgid = null;
            }
        }

        $commandPreview = $spec->commandPreview
            ?? mb_substr(implode(' ', $spec->command), 0, 120);

        $record = new ToolProcessRecordDTO(
            runId: $context->runId(),
            turnNo: $context->turnNo(),
            toolCallId: $context->toolCallId(),
            kind: ToolProcessKindEnum::ForegroundTool,
            pid: $pid,
            processGroupId: $pgid,
            commandPreview: $commandPreview,
            cwd: $spec->cwd,
            logPath: null, // Set by output cap integration in later tasks
            startedAt: new \DateTimeImmutable(),
        );

        $this->registry->register($record);

        $startedAt = hrtime(true);
        $timeoutSeconds = $spec->timeoutSeconds ?? $context->timeoutSeconds();

        $detached = false;

        try {
            // Wait loop: poll process output and check for observer/decision hooks.
            $this->waitForProcess($process, $context, $timeoutSeconds);

            $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
            $timedOut = $this->isTimedOut($process, $timeoutSeconds, $durationMs);
            $cancelled = $this->isCancelled($process, $context, $durationMs, $timeoutSeconds);
            $exitCode = $process->isRunning() ? null : $process->getExitCode();

            // If the process is still running but past timeout, terminate it.
            if ($timedOut && $process->isRunning()) {
                $this->terminator->terminate($record);
                $exitCode = null;
                $process->stop(0); // Signal Symfony to forget about it
            }

            // If cancelled post-run, terminate the process group.
            if ($cancelled && $process->isRunning()) {
                $this->terminator->terminate($record);
                $process->stop(0);
            }

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();

            // Decision hook for detach/background handoff.
            if (null !== $this->decisionHook && !$cancelled && !$timedOut) {
                $decision = ($this->decisionHook)($process, $context, $durationMs);
                if (self::DECISION_DETACH_BACKGROUND === $decision) {
                    $detached = true;
                // Ownership transferred to BackgroundProcessManager — do not unregister.
                // The record kind will be changed by the background manager.
                } elseif (self::DECISION_TERMINATE === $decision) {
                    $this->terminator->terminate($record);
                    $process->stop(0);
                    $exitCode = $process->getExitCode();
                }
            }

            return new ProcessRunResult(
                stdout: $stdout,
                stderr: $stderr,
                exitCode: $exitCode,
                cancelled: $cancelled,
                timedOut: $timedOut,
                outputPath: null, // Set by OutputCap integration in later tasks
                durationMs: $durationMs,
            );
        } finally {
            if (!$detached) {
                $this->registry->unregister($context->runId(), $context->toolCallId());
            }
        }
    }

    /**
     * Set the decision hook for bash background/termination decisions.
     *
     * @param callable(Process, ToolExecutionContextInterface, int $durationMs): string|null $hook
     */
    public function setDecisionHook(?callable $hook): void
    {
        $this->decisionHook = $hook;
    }

    /**
     * Check if the process has timed out.
     */
    private function isTimedOut(Process $process, int $timeoutSeconds, int $durationMs): bool
    {
        if ($timeoutSeconds <= 0) {
            return false;
        }

        if ($process->isRunning()) {
            return $durationMs > $timeoutSeconds * 1000;
        }

        return false;
    }

    /**
     * Check if cancellation was requested during or after process execution.
     */
    private function isCancelled(Process $process, ToolExecutionContextInterface $context, int $durationMs, int $timeoutSeconds): bool
    {
        // Check the cancellation token.
        if ($context->cancellationToken()->isCancellationRequested()) {
            return true;
        }

        // If the process was killed by SIGTERM/SIGKILL (exit code -1 on Unix),
        // it may have been terminated by the controller's cancellation hook.
        if (!$process->isRunning()) {
            $exitCode = $process->getExitCode();
            if (-1 === $exitCode || -9 === $exitCode || -15 === $exitCode) {
                // Could be cancellation — check the token to be sure.
                return $context->cancellationToken()->isCancellationRequested();
            }
        }

        return false;
    }

    /**
     * Busy-wait for process completion, with periodic cancellation checks.
     */
    private function waitForProcess(Process $process, ToolExecutionContextInterface $context, int $timeoutSeconds): void
    {
        $deadline = $timeoutSeconds > 0 ? microtime(true) + $timeoutSeconds : null;

        while ($process->isRunning()) {
            if ($context->cancellationToken()->isCancellationRequested()) {
                // Mark cancelled — the caller's finally block will unregister.
                // The process will be terminated by the controller's cancellation hook.
                // We just stop waiting and return.
                return;
            }

            if (null !== $deadline && microtime(true) >= $deadline) {
                // Timeout reached — let the caller handle termination.
                return;
            }

            usleep(100_000); // 100ms poll
        }
    }
}
