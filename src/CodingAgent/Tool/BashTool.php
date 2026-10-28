<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\MalformedToolResultException;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\BashToolConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tool\Arguments\BashArgumentsDTO;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\MapToolArguments;

use function Amp\delay;

/**
 * Execute a shell command with foreground supervision via BackgroundProcessManager.
 *
 * Implements HatfieldToolProviderInterface for automatic registration
 * as a permanent tool and the Symfony AI native tool contract (typed DTO arguments).
 *
 * Key design:
 * - Every bash command starts immediately through
 *   BackgroundProcessManager::start($command, $sessionId), guaranteeing
 *   exactly one command execution.
 * - A foreground supervision loop polls the manager for process status,
 *   checks ambient ToolContext cancellation, and enforces a monotonic
 *   timeout deadline.
 * - Ctrl+B requests detachment of one exact invocation. Its worker checks
 *   completion before accepting, then the existing controller poller owns
 *   supervision and completion notification for the same native process.
 * - On successful completion, returns captured capped output.
 * - On non-zero or unclean exit, throws ToolCallException with output and
 *   exit status so ToolExecutor preserves the failed result for the TUI.
 * - On timeout/cancellation, stops the managed process and returns
 *   partial output with a clear notice.
 * - Accepting backgrounding never launches a second copy of the command.
 *
 * Tool definition schema exposes only:
 *   - command (required): the shell command string
 *   - timeout (optional int): explicit timeout in seconds
 *
 * backgrounded (optional bool): request detachment after startup.
 *
 * ## Safety note: raw shell execution
 *
 * This tool intentionally executes the model-provided command string directly
 * via bash -c. That is the tool's purpose — giving the model a real shell.
 * Because of this, bash is excluded from real-LLM E2E tests by default
 * (--tools-excluded=bash) unless a test specifically needs to drive it.
 * The caller (AgentCommand) controls tool exposure per run through
 * --tools / --tools-excluded CLI options.
 *
 * Note: BackgroundProcessManager::start() warns callers to escape with
 * escapeshellarg(). That warning applies to callers that take user input
 * from untrusted sources. BashTool intentionally passes the model-provided
 * string directly — the model is treated as a trusted caller within the
 * same agent session.
 */
final class BashTool implements HatfieldToolProviderInterface
{
    public const string NAME = 'bash';

    public const string DESCRIPTION_TEMPLATE = 'Execute a shell command. By default, wait for completion. Set backgrounded=true to continue other work; you will be notified when the command finishes. Do not poll for completion. Commands time out after %d seconds by default. Provide the timeout argument with an explicit higher value when a command needs more time.';

    public function __construct(
        private readonly BackgroundProcessManager $manager,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
        private readonly LoggerInterface $logger,
        private readonly BashToolConfig $config = new BashToolConfig(),
    ) {
    }

    /**
     * Execute a bash command with foreground supervision.
     *
     * @param BashArgumentsDTO $arguments
     *                                    Optional 'timeout' (int|null)
     *
     * @return string Command output or backgrounding notice
     *
     * @throws ToolCallException on validation errors or execution failures
     */
    public function __invoke(
        #[MapToolArguments]
        BashArgumentsDTO $arguments,
    ): string {
        return $this->toolRuntime->run(function () use ($arguments): string {
            $command = trim($arguments->command);
            $timeout = $this->resolveTimeout($arguments);

            // Resolve session context.
            // When no ToolContext is active (e.g. tests bypassing context
            // wrapping, admin/tooling commands), $sessionId is null and
            // $cancelToken is null. BackgroundProcessManager treats null
            // sessionId as unscoped/admin — the process is stored with an
            // empty session and list/stop calls scoped to a session won't
            // see it. Cancellation is simply unavailable when no context
            // is present; only the timeout deadline bounds execution.
            $context = $this->contextAccessor->current();
            $sessionId = $context?->runId();
            $cancelToken = $context?->cancellationToken();

            // Start the command immediately through BackgroundProcessManager.
            // This guarantees exactly one process execution per tool call.
            $deadline = hrtime(true) + $timeout * 1_000_000_000;
            // Only direct model tools are targets, including child-run tools. Direct !shell has no
            // producing step; code-mode nested calls have a deeper context.
            $toolCallId = null !== $context?->stepId() && 1 === $this->contextAccessor->depth() ? $context->toolCallId() : null;
            if ($arguments->backgrounded && null === $toolCallId) {
                throw new ToolCallException('Background launch requires a top-level Bash tool invocation.', retryable: false);
            }
            $startResult = $this->manager->start($command, $sessionId, $toolCallId, $deadline, backgroundRequested: $arguments->backgrounded);
            $pid = $startResult->pid;
            $dbId = $startResult->id;
            $logPath = $startResult->logPath;

            $this->logger->info('bash_tool.started', [
                'component' => 'tool.bash',
                'event_type' => 'bash_tool.started',
                'process_pid' => $pid,
                'record_id' => $dbId,
                'log_path' => $logPath,
                'session_id' => $sessionId ?? 'none',
            ]);

            // Foreground supervision loop
            while (true) {
                // 1. Cooperative cancellation check from ambient ToolContext.
                //
                // The process is stopped, but the return value is discarded:
                // ToolRuntime::run() throws a RuntimeException for stale results
                // when it detects cancellation after the callback returns (see
                // src/AgentCore/Application/Handler/ToolExecutor.php which also
                // converts post-execution cancellation to an error result).
                // The meaningful work here is stop() + structured log; the
                // return value only serves local control flow and is never
                // user-visible.
                if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                    $this->manager->stopByRecordId($dbId, $sessionId);

                    $this->logger->info('bash_tool.cancelled', [
                        'component' => 'tool.bash',
                        'event_type' => 'bash_tool.cancelled',
                        'process_pid' => $pid,
                    ]);

                    return ''; // discarded by ToolRuntime; meaningful action is stop() above
                }

                // 2. Monotonic timeout deadline
                if (hrtime(true) > $deadline) {
                    $this->manager->stopByRecordId($dbId, $sessionId);
                    $partialOutput = $this->readOutput($dbId, $sessionId);
                    $this->logger->info('bash_tool.timed_out', [
                        'component' => 'tool.bash',
                        'event_type' => 'bash_tool.timed_out',
                        'process_pid' => $pid,
                        'timeout_seconds' => $timeout,
                    ]);

                    return \sprintf(
                        "Command timed out after %d seconds.\n\nPartial output:\n%s",
                        $timeout,
                        $partialOutput,
                    );
                }

                // 3. Poll process status from BackgroundProcessManager
                // Uses findByRecordId() with the immutable DB primary key rather
                // than find() with the OS PID, because PID-based lookups can
                // miss rows under SQLite write contention or when the ORM identity
                // map is stale (see #228). The DB record ID is unique, immutable,
                // and returned by start() in StartResult::$id.
                $record = $this->manager->findByRecordId($dbId, $sessionId);

                if (null === $record) {
                    // One-shot diagnostic: check ORM existence for both the
                    // record id and the pid to distinguish genuine absence
                    // from ORM/connection inconsistency.
                    $rowExistsById = $this->manager->existsByRecordId($dbId);
                    $rowExistsByPid = $this->manager->existsByPid($pid);

                    $this->logger->error('bash_tool.record_vanished', [
                        'component' => 'tool.bash',
                        'event_type' => 'bash_tool.record_vanished',
                        'process_pid' => $pid,
                        'record_id' => $dbId,
                        'session_id' => $sessionId ?? '',
                        'row_exists_by_id' => $rowExistsById,
                        'row_exists_by_pid' => $rowExistsByPid,
                    ]);

                    throw new ToolCallException(\sprintf('Background process record vanished unexpectedly (PID %d, record %d). DB row exists: by_id=%s, by_pid=%s. The process may have been cleaned up or the store is inconsistent.', $pid, $dbId, $rowExistsById ? 'true' : 'false', $rowExistsByPid ? 'true' : 'false'), retryable: false);
                }

                if (BackgroundProcessStatusEnum::Running !== $record->status) {
                    // Process finished (or stopped/finished uncleanly)
                    return $this->handleFinished($record, $sessionId);
                }

                if ($record->backgroundRequested) {
                    if (!$this->manager->tryMarkBackgroundedForRecord($dbId, $sessionId)) {
                        // Completion won the owner's transaction. Re-read it on
                        // the next pass instead of issuing a background ACK.
                        continue;
                    }

                    return \sprintf(
                        "Command moved to background.\nPID: %d\nLog: %s\n\nYou will be notified when the process finishes. Use bg_status log pid=%d to inspect output or bg_status stop pid=%d to stop it.",
                        $pid,
                        $logPath,
                        $pid,
                        $pid,
                    );
                }

                // Yield during supervision so sibling invocations and lease timers progress.
                delay($this->config->pollIntervalMicros / 1_000_000);
            }
        });
    }

    /**
     * Return the tool definition for automatic provider registration.
     */
    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: self::NAME,
            description: \sprintf(
                self::DESCRIPTION_TEMPLATE,
                $this->config->defaultTimeoutSeconds,
            ),
            handler: $this,
            executionMode: ToolExecutionMode::Parallel,
            promptLine: 'bash command [timeout=N] [backgrounded=true|false] — execute a shell command',
            promptGuidelines: [
                \sprintf(
                    'Bash commands time out after %d seconds by default. Always provide the timeout argument with an explicit higher value when a command needs more time, up to the %d-second maximum.',
                    $this->config->defaultTimeoutSeconds,
                    $this->config->maxTimeoutSeconds,
                ),
                'For file operations such as reading, writing, editing, or viewing files, prefer the dedicated read/write/edit/view_image tools instead of bash cat/echo/editor pipelines.',
                'Set backgrounded=true when you can continue other work without the command\'s result. The default, false, waits for completion. Background commands notify you when they finish; do not poll for completion. Use bg_status with the returned PID to inspect output or stop the command. Background launch is supported for direct bash calls, not calls inside code_mode.',
                'Output is capped to prevent excessively large responses. Very large output may be truncated and saved to a file for inspection.',
            ],
        );
    }

    // ─── Private helpers ────────────────────────────────────────────

    private function resolveTimeout(BashArgumentsDTO $arguments): int
    {
        // Argument bounds are validated natively (Assert\Range +
        // BashTimeoutMax against the configured max); this only applies
        // the configured default when the model omitted timeout.
        return $arguments->timeout ?? $this->config->defaultTimeoutSeconds;
    }

    /**
     * Read bounded output for the exact immutable background-process record.
     *
     * Foreground completion must never materialize multi-megabyte logs into PHP
     * strings. Use the existing log tail bound (bash.log_tail_chars). When the
     * log exceeds that bound, return a compact notice that points at the live
     * background log path (the full artifact) instead of duplicating into
     * output-cap storage.
     *
     * @param int         $recordId  Immutable background-process record ID
     * @param string|null $sessionId Session ownership filter
     *
     * @return string The log content, or a compact oversized-output notice
     */
    private function readOutput(int $recordId, ?string $sessionId): string
    {
        try {
            // Use the immutable DB record ID — never re-resolve by OS PID.
            $result = $this->manager->readLogTailForRecord(
                $recordId,
                $this->config->logTailChars,
                $sessionId,
            );

            if (!$result->truncated) {
                return $result->content;
            }

            // Keep this notice well under the generic OutputCap default so the
            // central processor does not re-cap it or copy the background log.
            return \sprintf(
                "[Bash output truncated: %d bytes > %d-byte read bound]\n".
                "Full output remains at the background log:\n%s\n".
                "\n".
                "Next: inspect a bounded byte window as hexadecimal, e.g.\n".
                "- bash(command: \"od -An -tx1 -N %d %s\")\n".
                "Use od -j OFFSET for a later window. Do not cut UTF-8 text with tail -c.\n".
                'Do not rerun the original command or load the full log unbound.',
                $result->totalBytes,
                $this->config->logTailChars,
                $result->logPath,
                $this->config->logTailChars,
                escapeshellarg($result->logPath),
            );
        } catch (MalformedToolResultException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            $this->logger->warning('bash_tool.read_output_failed', [
                'component' => 'tool.bash',
                'event_type' => 'bash_tool.read_output_failed',
                'record_id' => $recordId,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Handle a finished/stopped process entity.
     *
     * @param BackgroundProcess $entity    The finished process entity
     * @param string|null       $sessionId Session ownership filter
     *
     * @return string Formatted result text
     */
    private function handleFinished(BackgroundProcess $entity, ?string $sessionId): string
    {
        $output = $this->readOutput($entity->id, $sessionId);

        $pid = $entity->pid;

        $exitCode = $entity->exitCode;
        $status = $entity->status->value;

        // Check if this was a user-requested stop (bg_status stop)
        // before treating exit code 0 as normal success, so a
        // user-stopped command can never be misreported as successful.
        if ($entity->stoppedByUser) {
            throw new ToolCallException(\sprintf("Command was stopped (exit code %d).\n\nOutput:\n%s", $exitCode ?? -1, $output));
        }

        // Normal successful completion
        if (0 === $exitCode) {
            $this->logger->info('bash_tool.completed', [
                'component' => 'tool.bash',
                'event_type' => 'bash_tool.completed',
                'process_pid' => $pid,
                'exit_code' => $exitCode,
            ]);

            return $output;
        }

        // Non-zero exit code or finished/unclean status
        $this->logger->info('bash_tool.failed', [
            'component' => 'tool.bash',
            'event_type' => 'bash_tool.failed',
            'process_pid' => $pid,
            'exit_code' => $exitCode,
            'status' => $status,
            'log_path' => $entity->logPath,
            'record_id' => $entity->id,
        ]);

        // A missing exit code is an unclean completion, never a success.
        // Do not return this diagnostic as ordinary text: that loses the
        // failed outcome at the toolbox boundary even though it says failed.
        $failure = null !== $exitCode
            ? \sprintf('Command failed with exit code %d.', $exitCode)
            : 'Command supervision ended without an exit status.';
        $logHint = null === $exitCode && '' !== $entity->logPath
            ? \sprintf("\n\nThe supervisor exited without recording the command's final status. Workload outcome is unknown; do not blindly rerun it.\nLog: %s\nStatus file: %s\nPID: %d\nRecord: %d\nInspect these files with read. Foreground records are not exposed by bg_status.", $entity->logPath, $entity->statusPath, $pid, $entity->id)
            : '';

        throw new ToolCallException(\sprintf("%s\n\nOutput:\n%s%s", $failure, $output, $logHint));
    }
}
