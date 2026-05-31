<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\BashToolConfig;
use Ineersa\CodingAgent\Tool\BackgroundProcess\BackgroundProcessRecord;
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
 */
final class BashTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    public function __construct(
        private readonly BackgroundProcessManager $manager,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
        private readonly OutputCap $outputCap,
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

            // Resolve session context
            $context = $this->contextAccessor->current();
            $sessionId = $context?->runId();
            $cancelToken = $context?->cancellationToken();

            // Start the command immediately through BackgroundProcessManager.
            // This guarantees exactly one process execution per tool call.
            $startResult = $this->manager->start($command, $sessionId);
            $pid = $startResult->pid;
            $logPath = $startResult->logPath;

            $this->logger->info('bash_tool.started', [
                'component' => 'tool.bash',
                'event_type' => 'bash_tool.started',
                'process_pid' => $pid,
                'log_path' => $logPath,
                'session_id' => $sessionId ?? 'none',
            ]);

            // Compute monotonic deadline for timeout enforcement
            $deadline = hrtime(true) + $timeout * 1_000_000_000;

            $startTime = hrtime(true);
            $promptTriggered = false;

            // Foreground supervision loop
            while (true) {
                // 1. Cooperative cancellation check from ambient ToolContext
                if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                    $this->manager->stop($pid, $sessionId);
                    $partialOutput = $this->readOutput($pid, $sessionId);

                    $this->logger->info('bash_tool.cancelled', [
                        'component' => 'tool.bash',
                        'event_type' => 'bash_tool.cancelled',
                        'process_pid' => $pid,
                    ]);

                    $result = 'Tool execution cancelled.';
                    if ('' !== $partialOutput) {
                        $result .= "\n\nPartial output:\n".$partialOutput;
                    }

                    return $result;
                }

                // 2. Monotonic timeout deadline
                if (null !== $deadline && hrtime(true) > $deadline) {
                    $this->manager->stop($pid, $sessionId);
                    $partialOutput = $this->readOutput($pid, $sessionId);
                    $capped = $this->outputCap->process($partialOutput);

                    $this->logger->info('bash_tool.timed_out', [
                        'component' => 'tool.bash',
                        'event_type' => 'bash_tool.timed_out',
                        'process_pid' => $pid,
                        'timeout_seconds' => $timeout,
                    ]);

                    return \sprintf(
                        "Command timed out after %d seconds.\n\nPartial output:\n%s",
                        $timeout,
                        $capped,
                    );
                }

                // 3. Poll process status from BackgroundProcessManager
                $record = $this->findProcessRecord($pid, $sessionId);

                if (null === $record) {
                    // Process record vanished — should not happen under normal
                    // operation, but guard against edge cases.
                    throw new ToolCallException('Background process record vanished unexpectedly; the process may have been cleaned up.', retryable: false);
                }

                if ('running' !== $record->status) {
                    // Process finished (or stopped/finished uncleanly)
                    return $this->handleFinished($record, $pid, $sessionId);
                }

                // 4. Background prompt threshold check (once per invocation)
                if (!$promptTriggered) {
                    $elapsedSeconds = (hrtime(true) - $startTime) / 1_000_000_000;

                    if ($elapsedSeconds >= $this->config->backgroundPromptThresholdSeconds) {
                        $promptTriggered = true;

                        if ($this->promptAdapter->shouldBackground($command, $pid, $logPath, $elapsedSeconds)) {
                            $this->logger->info('bash_tool.backgrounded', [
                                'component' => 'tool.bash',
                                'event_type' => 'bash_tool.backgrounded',
                                'process_pid' => $pid,
                                'log_path' => $logPath,
                            ]);

                            return \sprintf(
                                "Command moved to background.\nPID: %d\nLog: %s\n\nUse bg_status log pid=%d to check output, or bg_status stop pid=%d to terminate.",
                                $pid,
                                $logPath,
                                $pid,
                                $pid,
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
            description: 'Execute a shell command with timeout. The command runs until completion, hits the timeout, or is cancelled. Long-running commands may be offered to move to background after 30 seconds.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'command' => [
                        'type' => 'string',
                        'description' => 'Shell command to execute (e.g., "ls -la", "cat file.txt", "git status")',
                    ],
                    'timeout' => [
                        'type' => 'integer',
                        'description' => 'Timeout in seconds (default: 300). Use for commands that may hang.',
                        'minimum' => 1,
                    ],
                ],
                'required' => ['command'],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'bash command [timeout=N] — execute a shell command with foreground supervision and optional timeout',
            promptGuidelines: [
                'Use bash for running shell commands, scripts, build tools, and git operations.',
                'For file operations such as reading, writing, editing, or viewing files, prefer the dedicated read/write/edit/view_image tools instead of bash cat/echo/editor pipelines.',
                'Commands run until completion. Use the optional timeout parameter for commands that may hang (e.g., network operations, long builds).',
                'Long-running commands (over 30 seconds) may be offered to move to background. If backgrounded, use bg_status log to view output and bg_status stop to terminate.',
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

        return $timeout;
    }

    /**
     * Find a process record by PID from the manager's list.
     *
     * @param int         $pid       Process PID to find
     * @param string|null $sessionId Session filter
     *
     * @return BackgroundProcessRecord|null The matching record, or null if not found
     */
    private function findProcessRecord(int $pid, ?string $sessionId): ?BackgroundProcessRecord
    {
        $records = $this->manager->list($sessionId);

        foreach ($records as $record) {
            if ($record->pid === $pid) {
                return $record;
            }
        }

        return null;
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
            $result = $this->manager->readLogTail($pid, $this->config->logTailChars, $sessionId);

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
     * Handle a finished/stopped process record.
     *
     * @param BackgroundProcessRecord $record    The finished process record
     * @param int                     $pid       Process PID
     * @param string|null             $sessionId Session ownership filter
     *
     * @return string Formatted result with capped output
     */
    private function handleFinished(BackgroundProcessRecord $record, int $pid, ?string $sessionId): string
    {
        $output = $this->readOutput($pid, $sessionId);
        $capped = $this->outputCap->process($output);

        $exitCode = $record->exitCode;
        $status = $record->status;

        // Normal successful completion
        if (0 === $exitCode) {
            $this->logger->info('bash_tool.completed', [
                'component' => 'tool.bash',
                'event_type' => 'bash_tool.completed',
                'process_pid' => $pid,
                'exit_code' => $exitCode,
            ]);

            return $capped;
        }

        // Non-zero exit code or stopped/finished unclean status
        $this->logger->info('bash_tool.failed', [
            'component' => 'tool.bash',
            'event_type' => 'bash_tool.failed',
            'process_pid' => $pid,
            'exit_code' => $exitCode,
            'status' => $status,
        ]);

        // Check if this was a user-requested stop (bg_status stop)
        if ($record->stoppedByUser) {
            return \sprintf(
                "Command was stopped (exit code %d).\n\nOutput:\n%s",
                $exitCode ?? -1,
                $capped,
            );
        }

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
                $capped,
            );
        }

        // Fallback: just return the output
        return $capped;
    }
}
