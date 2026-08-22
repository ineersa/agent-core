<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Config\BashToolConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tool\Arguments\BashArgumentsDTO;
use Psr\Log\LoggerInterface;

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
 * - At the configured background_prompt_threshold_seconds, parent/main
 *   sessions ask the injectable BashBackgroundPromptAdapterInterface
 *   whether to leave the process running in background. CodingAgent
 *   resolves child/fork/subagent runs from cached immutable RunStarted
 *   metadata and skips that optional HITL so those invocations stay
 *   foreground-supervised and the per-call Bash timeout can still fire.
 * - On successful completion, returns captured capped output.
 * - On non-zero exit, returns output + exit code info.
 * - On timeout/cancellation, stops the managed process and returns
 *   partial output with a clear notice.
 * - Accepting backgrounding never launches a second copy of the command.
 *
 * Tool definition schema exposes only:
 *   - command (required): the shell command string
 *   - timeout (optional int): explicit timeout in seconds
 *
 * No model-controlled run_in_background parameter.
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

    public const string DESCRIPTION_TEMPLATE = 'Execute a shell command with timeout. The command runs until completion, hits the timeout, or is cancelled. Long-running commands may be offered to move to background after %d seconds.';

    public function __construct(
        private readonly BackgroundProcessManager $manager,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
        private readonly LoggerInterface $logger,
        private readonly SubagentRunMetadataReader $runMetadataReader,
        private readonly BashToolConfig $config = new BashToolConfig(),
        private readonly BashBackgroundPromptAdapterInterface $promptAdapter = new BashBackgroundPromptDeclineAdapter(),
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
    public function __invoke(BashArgumentsDTO $arguments): string
    {
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

            // Resolve CodingAgent-owned background-prompt eligibility once
            // before starting the process. AgentCore ToolContext only provides
            // the run id; child classification stays in CodingAgent.
            $allowBackgroundPrompt = $this->resolveBackgroundPromptAllowed($sessionId);

            // Start the command immediately through BackgroundProcessManager.
            // This guarantees exactly one process execution per tool call.
            $startResult = $this->manager->start($command, $sessionId);
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

            // Compute monotonic deadline for timeout enforcement
            $deadline = hrtime(true) + $timeout * 1_000_000_000;

            $startTime = hrtime(true);
            $promptTriggered = false;

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

                // 4. Background prompt threshold check (once per invocation).
                // Child/fork runs skip the HITL offer: awaiting it would block
                // this loop and prevent the Bash deadline above from firing
                // (session #41 fork hang).
                if (!$promptTriggered) {
                    $elapsedSeconds = (hrtime(true) - $startTime) / 1_000_000_000;

                    if ($elapsedSeconds >= $this->config->backgroundPromptThresholdSeconds) {
                        $promptTriggered = true;

                        if (!$allowBackgroundPrompt) {
                            // Policy path: continue foreground supervision.
                        } elseif ($this->promptAdapter->shouldBackground($command, $pid, $logPath, $elapsedSeconds)) {
                            // Re-check process status — it may have finished while we
                            // were waiting for the user's decision. If the process
                            // completed, return the finished output instead of the
                            // backgrounding notice. This avoids a misleading
                            // "Command moved to background" message when the process
                            // already exited during the prompt wait.
                            $recheck = $this->manager->findByRecordId($dbId, $sessionId);
                            if (null !== $recheck && BackgroundProcessStatusEnum::Running !== $recheck->status) {
                                $this->logger->info('bash_tool.background_process_completed_during_prompt', [
                                    'component' => 'tool.bash',
                                    'event_type' => 'bash_tool.background_process_completed_during_prompt',
                                    'process_pid' => $pid,
                                ]);

                                return $this->handleFinished($recheck, $sessionId);
                            }

                            // Mark the process as backgrounded so the
                            // BackgroundProcessCompletionPoller can notify on completion.
                            $this->manager->markBackgroundedForRecord($dbId, $sessionId);

                            $this->logger->info('bash_tool.backgrounded', [
                                'component' => 'tool.bash',
                                'event_type' => 'bash_tool.backgrounded',
                                'process_pid' => $pid,
                                'log_path' => $logPath,
                            ]);

                            $pidStr = (string) $pid;

                            return \sprintf(
                                "Command moved to background.\nPID: %d\nLog: %s\n\nYou will be notified when the process finishes.\n\nYou can also check output with:\n  bg_status log pid=%s\n\nOr stop it with:\n  bg_status stop pid=%s",
                                $pid,
                                $logPath,
                                $pidStr,
                                $pidStr,
                            );
                        } else {
                            $this->logger->info('bash_tool.background_declined', [
                                'component' => 'tool.bash',
                                'event_type' => 'bash_tool.background_declined',
                                'process_pid' => $pid,
                            ]);
                        }
                    }
                }

                // 5. Sleep before next poll
                usleep($this->config->pollIntervalMicros);
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
            description: \sprintf(self::DESCRIPTION_TEMPLATE, $this->config->backgroundPromptThresholdSeconds),
            handler: $this,
            executionMode: ToolExecutionMode::Parallel,
            promptLine: 'bash command [timeout=N] — execute a shell command with foreground supervision and optional timeout',
            promptGuidelines: [
                'For file operations such as reading, writing, editing, or viewing files, prefer the dedicated read/write/edit/view_image tools instead of bash cat/echo/editor pipelines.',
                'The user controls backgrounding; there is no run_in_background argument. Backgrounded commands report completion automatically, so do not poll; use bg_status with the returned PID only to inspect progress or stop the process when needed.',
                'Output is capped to prevent excessively large responses. Very large output may be truncated and saved to a file for inspection.',
            ],
        );
    }

    /**
     * Parent/missing/no-context runs keep normal background prompting.
     * Canonical agent_child metadata disables it for this invocation.
     * Lookup/malformed failures degrade locally by disabling the optional prompt.
     */
    private function resolveBackgroundPromptAllowed(?string $sessionId): bool
    {
        if (null === $sessionId || '' === $sessionId) {
            return true;
        }

        try {
            return !$this->runMetadataReader->isAgentChild($sessionId);
        } catch (\Throwable $e) {
            $this->logger->warning('bash_tool.background_policy_resolution_failed', [
                'run_id' => $sessionId,
                'session_id' => $sessionId,
                'component' => 'tool.bash',
                'event_type' => 'bash_tool.background_policy_resolution_failed',
                'error_class' => $e::class,
            ]);

            return false;
        }
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
                "Next: inspect the log with a bound, e.g.\n".
                "- bash(command: \"tail -c %d %s\")\n".
                "- bash(command: \"grep -n -- 'PATTERN' %s | head -50\")\n".
                'Do not rerun the original command or load the full log unbound.',
                $result->totalBytes,
                $this->config->logTailChars,
                $result->logPath,
                $this->config->logTailChars,
                escapeshellarg($result->logPath),
                escapeshellarg($result->logPath),
            );
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
            return \sprintf(
                "Command was stopped (exit code %d).\n\nOutput:\n%s",
                $exitCode ?? -1,
                $output,
            );
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
        ]);

        // Build status suffix for non-zero / unclean exits
        $statusSuffix = '';
        if (null !== $exitCode) {
            $statusSuffix = \sprintf('exit code %d', $exitCode);
        } elseif (str_contains($status, 'unclean')) {
            $statusSuffix = 'unclean exit';
        }

        if ('' !== $statusSuffix) {
            return \sprintf(
                "Command failed with %s.\n\nOutput:\n%s",
                $statusSuffix,
                $output,
            );
        }

        // Fallback: just return the output
        return $output;
    }
}
