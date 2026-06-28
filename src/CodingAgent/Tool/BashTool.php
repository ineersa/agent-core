<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\BashToolConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Psr\Log\LoggerInterface;

/**
 * Execute a shell command with foreground supervision via BackgroundProcessManager.
 *
 * Implements both HatfieldToolProviderInterface for automatic registration
 * as a permanent tool and ToolHandlerInterface for execution.
 *
 * Key design:
 * - Every bash command starts immediately through
 *   BackgroundProcessManager::start($command, $sessionId), guaranteeing
 *   exactly one command execution.
 * - A foreground supervision loop polls the manager for process status,
 *   checks ambient ToolContext cancellation, and enforces a monotonic
 *   timeout deadline.
 * - At the configured background_prompt_threshold_seconds, the tool asks
 *   the injectable BashBackgroundPromptAdapterInterface whether to leave
 *   the process running in background. The TOOLS-09 default declines.
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
final class BashTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    public function __construct(
        private readonly BackgroundProcessManager $manager,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
        private readonly LoggerInterface $logger,
        private readonly BashToolConfig $config = new BashToolConfig(),
        private readonly BashBackgroundPromptAdapterInterface $promptAdapter = new BashBackgroundPromptDeclineAdapter(),
    ) {
    }

    /**
     * Execute a bash command with foreground supervision.
     *
     * @param array<string, mixed> $arguments Must contain 'command' (string).
     *                                        Optional 'timeout' (int|null).
     *
     * @return string Command output or backgrounding notice
     *
     * @throws ToolCallException on validation errors or execution failures
     */
    public function __invoke(array $arguments): string
    {
        return $this->toolRuntime->run(function () use ($arguments): string {
            // Validate and extract arguments
            $command = $this->validateCommand($arguments);
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
                    $this->manager->stop($pid, $sessionId);

                    $this->logger->info('bash_tool.cancelled', [
                        'component' => 'tool.bash',
                        'event_type' => 'bash_tool.cancelled',
                        'process_pid' => $pid,
                    ]);

                    return ''; // discarded by ToolRuntime; meaningful action is stop() above
                }

                // 2. Monotonic timeout deadline
                if (hrtime(true) > $deadline) {
                    $this->manager->stop($pid, $sessionId);
                    $partialOutput = $this->readOutput($pid, $sessionId);
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
                    return $this->handleFinished($record, $pid, $sessionId);
                }

                // 4. Background prompt threshold check (once per invocation)
                if (!$promptTriggered) {
                    $elapsedSeconds = (hrtime(true) - $startTime) / 1_000_000_000;

                    if ($elapsedSeconds >= $this->config->backgroundPromptThresholdSeconds) {
                        $promptTriggered = true;

                        if ($this->promptAdapter->shouldBackground($command, $pid, $logPath, $elapsedSeconds)) {
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

                                return $this->handleFinished($recheck, $pid, $sessionId);
                            }

                            // Mark the process as backgrounded so the
                            // BackgroundProcessCompletionPoller can notify on completion.
                            $this->manager->markBackgrounded($pid, $sessionId);

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
                        }

                        $this->logger->info('bash_tool.background_declined', [
                            'component' => 'tool.bash',
                            'event_type' => 'bash_tool.background_declined',
                            'process_pid' => $pid,
                        ]);
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
            name: 'bash',
            description: \sprintf('Execute a shell command with timeout. The command runs until completion, hits the timeout, or is cancelled. Long-running commands may be offered to move to background after %d seconds.', $this->config->backgroundPromptThresholdSeconds),
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'command' => [
                        'type' => 'string',
                        'description' => 'Shell command to execute (e.g., "ls -la", "cat file.txt", "git status")',
                    ],
                    'timeout' => [
                        'type' => 'integer',
                        'description' => \sprintf('Timeout in seconds (default: %d, max: %d). Use for commands that may hang.', $this->config->defaultTimeoutSeconds, $this->config->maxTimeoutSeconds),
                        'minimum' => 1,
                        'maximum' => $this->config->maxTimeoutSeconds,
                    ],
                ],
                'required' => ['command'],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Parallel,
            promptLine: 'bash command [timeout=N] — execute a shell command with foreground supervision and optional timeout',
            promptGuidelines: [
                'Use bash for running shell commands, scripts, build tools, and git operations.',
                'For file operations such as reading, writing, editing, or viewing files, prefer the dedicated read/write/edit/view_image tools instead of bash cat/echo/editor pipelines.',
                'Commands run until completion. Use the optional timeout parameter for commands that may hang (e.g., network operations, long builds).',
                \sprintf('Long-running commands (over %d seconds) may be offered to the user to move to background. The model does not control backgrounding — the user chooses when prompted. When the user accepts, the tool returns a backgrounding notice with PID and log path. Use bg_status log/stop on the returned PID to inspect or terminate already-backgrounded processes.', $this->config->backgroundPromptThresholdSeconds),
                'There is no run_in_background parameter. Do not attempt to background a command through tool arguments.',
                'The command string is passed directly to bash -c. Use proper escaping for special characters.',
                'Output is capped to prevent excessively large responses. Very large output may be truncated and saved to a file for inspection.',
            ],
        );
    }

    // ─── Private helpers ────────────────────────────────────────────

    /**
     * Validate the command argument.
     *
     * @param array<string, mixed> $arguments
     *
     * @return string The validated command string
     *
     * @throws ToolCallException when command is missing or invalid
     */
    private function validateCommand(array $arguments): string
    {
        $command = $arguments['command'] ?? null;

        if (!\is_string($command) || '' === trim($command)) {
            throw new ToolCallException('The "command" argument is required and must be a non-empty string.', retryable: false, hint: 'Provide a shell command to execute, e.g., {"command": "ls -la"}');
        }

        return $command;
    }

    /**
     * Resolve the effective timeout from the arguments or config default.
     *
     * @param array<string, mixed> $arguments
     *
     * @return int Timeout seconds (config default when not explicitly provided)
     */
    private function resolveTimeout(array $arguments): int
    {
        $timeout = $arguments['timeout'] ?? null;

        if (null === $timeout) {
            return $this->config->defaultTimeoutSeconds;
        }

        if (!\is_int($timeout) || $timeout < 1) {
            throw new ToolCallException('The "timeout" argument must be a positive integer.', retryable: false, hint: 'Provide a positive integer for timeout seconds, or omit to use the default (300).');
        }

        $maxTimeout = $this->config->maxTimeoutSeconds;
        if ($timeout > $maxTimeout) {
            throw new ToolCallException(\sprintf('Timeout must not exceed %d seconds (%d provided).', $maxTimeout, $timeout), retryable: false, hint: \sprintf('Reduce the timeout to at most %d seconds, or omit to use the default (%d).', $maxTimeout, $this->config->defaultTimeoutSeconds));
        }

        return $timeout;
    }

    /**
     * Read the log tail output from a background process.
     *
     * @param int         $pid       Process PID
     * @param string|null $sessionId Session ownership filter
     *
     * @return string The log content, or empty string on failure
     */
    private function readOutput(int $pid, ?string $sessionId): string
    {
        try {
            // Read full log content for completed foreground commands so the
            // central OutputCapToolResultProcessor sees the actual command output
            // and produces the primary cap / compact ToolResult.  Tail-only reads
            // hide the true output size and force late-hook double-capping.
            $result = $this->manager->readLogFull($pid, $sessionId);

            return $result->content;
        } catch (\RuntimeException $e) {
            $this->logger->warning('bash_tool.read_output_failed', [
                'component' => 'tool.bash',
                'event_type' => 'bash_tool.read_output_failed',
                'process_pid' => $pid,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Handle a finished/stopped process entity.
     *
     * @param BackgroundProcess $entity    The finished process entity
     * @param int               $pid       Process PID
     * @param string|null       $sessionId Session ownership filter
     *
     * @return string Formatted result text
     */
    private function handleFinished(BackgroundProcess $entity, int $pid, ?string $sessionId): string
    {
        $output = $this->readOutput($pid, $sessionId);

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
