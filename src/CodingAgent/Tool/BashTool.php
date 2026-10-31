<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\MalformedToolResultException;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\BashToolConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tool\Arguments\BashArgumentsDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\NativeBashObservationDTO;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\MapToolArguments;

use function Amp\delay;

/**
 * Execute a shell command with foreground supervision via BackgroundProcessManager.
 *
 * Bash owns native execution, replay evidence and background metadata. Results
 * contain ordinary output or acknowledgments, never owner lifecycle instructions.
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
        private readonly ToolPayloadStoreInterface $payloads,
        private readonly BashToolConfig $config = new BashToolConfig(),
    ) {
    }

    public function __invoke(
        #[MapToolArguments]
        BashArgumentsDTO $arguments,
    ): string {
        return $this->toolRuntime->run(function () use ($arguments): string {
            $command = trim($arguments->command);
            $timeout = $this->resolveTimeout($arguments);
            $context = $this->contextAccessor->current();
            $sessionId = $context?->runId();
            $cancelToken = $context?->cancellationToken();
            $deadline = hrtime(true) + $timeout * 1_000_000_000;

            $outerInvocation = 1 === $this->contextAccessor->depth();
            $topLevelModel = null !== $context?->stepId() && $outerInvocation;
            $toolCallId = $context?->toolCallId();
            if ($arguments->backgrounded && (!$topLevelModel || null === $sessionId || '' === $sessionId || null === $toolCallId || '' === $toolCallId)) {
                throw new ToolCallException('Background launch requires a top-level Bash tool invocation.', retryable: false);
            }
            $startResult = $this->manager->start(
                $command,
                $sessionId,
                $outerInvocation ? $toolCallId : null,
                $deadline,
                backgroundRequested: $arguments->backgrounded,
                // Nested code-mode calls have random IDs and are not replayed.
                // Their caller retains output, but owns native-sidecar cleanup.
                invocationKey: $outerInvocation && null !== $context ? json_encode([$context->turnNo(), $context->stepId(), $toolCallId], \JSON_THROW_ON_ERROR) : null,
            );
            $pid = $startResult->pid;
            $statusPath = $startResult->statusPath;
            $logPath = $startResult->logPath;
            $retainedOutputPath = null;
            $deadline = $startResult->deadlineNanoseconds ?? $deadline;
            if ($startResult->accepted) {
                return $this->backgroundAcknowledgment($pid);
            }

            $this->logger->info('bash_tool.started', [
                'component' => 'tool.bash',
                'event_type' => 'bash_tool.started',
                'process_pid' => $pid,
                'status_path' => $statusPath,
                'log_path' => $logPath,
                'session_id' => $sessionId ?? 'none',
            ]);

            // Keep the native handle through output/metadata consumption, then
            // release once at this invocation boundary — including when output
            // reading throws (invalid UTF-8). Accepted detach already released.
            try {
                while (true) {
                    $record = $this->manager->observeNativeByStatusPath($statusPath, $sessionId);
                    if (null === $record) {
                        $this->logger->error('bash_tool.native_launch_vanished', [
                            'component' => 'tool.bash',
                            'event_type' => 'bash_tool.native_launch_vanished',
                            'process_pid' => $pid,
                            'status_path' => $statusPath,
                            'session_id' => $sessionId ?? '',
                        ]);

                        throw new ToolCallException(\sprintf('Native Bash launch vanished unexpectedly (PID %d).', $pid), retryable: false);
                    }

                    if (BackgroundProcessStatusEnum::Running !== $record->status) {
                        return $this->handleFinished($record, $sessionId, $toolCallId, $retainedOutputPath);
                    }

                    if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                        return $this->cancelNative($statusPath, $sessionId, $pid, $toolCallId);
                    }

                    if (hrtime(true) > $deadline) {
                        $this->manager->stopByStatusPath($statusPath, $sessionId);
                        $partialOutput = $this->readOutputForNativePaths(
                            $logPath,
                            $statusPath,
                            $sessionId,
                            $toolCallId,
                            $retainedOutputPath,
                        );
                        $this->logger->info('bash_tool.timed_out', [
                            'component' => 'tool.bash',
                            'event_type' => 'bash_tool.timed_out',
                            'process_pid' => $pid,
                            'timeout_seconds' => $timeout,
                        ]);
                        $text = \sprintf(
                            "Command timed out after %d seconds.\n\nPartial output:\n%s",
                            $timeout,
                            $partialOutput,
                        );
                        throw new ToolCallException($text, retryable: false);
                    }

                    if ($record->backgroundRequested) {
                        $candidate = $this->manager->resolveBackgroundAcceptance($statusPath, $sessionId);
                        if (null === $candidate) {
                            continue;
                        }
                        // Freshest cancel check after observation/identity resolution
                        // and immediately before the local acceptance transition.
                        if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                            return $this->cancelNative($statusPath, $sessionId, $pid, $toolCallId);
                        }
                        $acknowledgment = $this->backgroundAcknowledgment($pid);
                        if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                            return $this->cancelNative($statusPath, $sessionId, $pid, $toolCallId);
                        }
                        if (!$this->manager->acceptResolvedBackground($candidate)) {
                            continue;
                        }

                        return $acknowledgment;
                    }

                    delay($this->config->pollIntervalMicros / 1_000_000);
                }
            } finally {
                $this->manager->releaseNativeInvocation($statusPath);
            }
        });
    }

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

    private function resolveTimeout(BashArgumentsDTO $arguments): int
    {
        return $arguments->timeout ?? $this->config->defaultTimeoutSeconds;
    }

    private function cancelNative(
        string $statusPath,
        ?string $sessionId,
        int $pid,
        ?string $toolCallId,
    ): never {
        $this->manager->stopByStatusPath($statusPath, $sessionId);
        $this->logger->info('bash_tool.cancelled', [
            'component' => 'tool.bash',
            'event_type' => 'bash_tool.cancelled',
            'process_pid' => $pid,
            'status_path' => $statusPath,
            'session_id' => $sessionId ?? '',
            'tool_call_id' => $toolCallId ?? '',
        ]);

        throw new ToolCallException(\sprintf('Bash command cancelled (PID %d).', $pid), retryable: false);
    }

    /**
     * Bound the visible Bash log and retain the complete bytes when truncated.
     *
     * Retention uses the existing session attachment store so the referenced
     * path outlives disposable batch bodies and nested sidecar cleanup.
     */
    private function readOutputForNativePaths(
        string $logPath,
        string $statusPath,
        ?string $runId,
        ?string $toolCallId,
        ?string &$retainedOutputPath,
    ): string {
        try {
            $result = $this->manager->readLogTailForNativePaths(
                $logPath,
                $statusPath,
                $this->config->logTailChars,
            );

            if (!$result->truncated) {
                return $result->content;
            }

            $savedPath = $this->retainNativeLog($logPath, $runId, $toolCallId, $retainedOutputPath);

            // Keep this notice well under the generic OutputCap default so the
            // central processor does not re-cap it or copy the temporary sidecar.
            return \sprintf(
                "[Bash output truncated: %d bytes > %d-byte read bound]\n".
                "Full output saved for inspection:\n%s\n".
                "\n".
                "Next: inspect a bounded byte window as hexadecimal, e.g.\n".
                "  read path=%s offset=0 limit=64\n".
                'or use an explicit character window with a text encoding if you need readable text.',
                $result->totalBytes,
                $this->config->logTailChars,
                $savedPath,
                $savedPath,
            );
        } catch (MalformedToolResultException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ToolCallException(\sprintf('Failed to read Bash output: %s', $exception->getMessage()), retryable: false, previous: $exception);
        }
    }

    /**
     * @param-out string $retainedOutputPath
     */
    private function retainNativeLog(
        string $logPath,
        ?string $runId,
        ?string $toolCallId,
        ?string &$retainedOutputPath,
    ): string {
        if (null !== $retainedOutputPath) {
            return $retainedOutputPath;
        }
        if (null === $runId || '' === $runId || null === $toolCallId || '' === $toolCallId) {
            throw new ToolCallException('Bash output retention requires an invocation identity.', retryable: false);
        }
        if (!is_file($logPath) || !is_readable($logPath)) {
            throw new ToolCallException('Bash output retention requires a readable command log.', retryable: false);
        }

        $retainedOutputPath = $this->payloads->saveAttachment($runId, $toolCallId, static function ($handle) use ($logPath): void {
            $source = fopen($logPath, 'rb');
            if (false === $source) {
                throw new \RuntimeException('Failed to open Bash log for retention.');
            }
            try {
                if (false === stream_copy_to_stream($source, $handle)) {
                    throw new \RuntimeException('Failed to retain Bash log bytes.');
                }
            } finally {
                fclose($source);
            }
        });

        return $retainedOutputPath;
    }

    private function handleFinished(
        NativeBashObservationDTO $entity,
        ?string $sessionId,
        ?string $toolCallId,
        ?string &$retainedOutputPath,
    ): string {
        $output = $this->readOutputForNativePaths(
            $entity->logPath,
            $entity->statusPath,
            $sessionId,
            $toolCallId,
            $retainedOutputPath,
        );
        $pid = $entity->pid;
        $exitCode = $entity->exitCode;
        $status = $entity->status;

        $this->logger->info('bash_tool.completed', [
            'component' => 'tool.bash',
            'event_type' => 'bash_tool.completed',
            'process_pid' => $pid,
            'exit_code' => $exitCode,
            'status' => $status,
            'log_path' => $entity->logPath,
            'status_path' => $entity->statusPath,
        ]);

        if (BackgroundProcessStatusEnum::Finished === $status && 0 === $exitCode) {
            return $output;
        }

        $failure = null !== $exitCode
            ? \sprintf('Command failed with exit code %d.', $exitCode)
            : 'Command supervision ended without an exit status.';
        $logHint = '';
        if (null === $exitCode) {
            $savedPath = null;
            if ('' !== $entity->logPath && is_file($entity->logPath) && is_readable($entity->logPath)) {
                $savedPath = $this->retainNativeLog($entity->logPath, $sessionId, $toolCallId, $retainedOutputPath);
            }
            $logHint = null !== $savedPath
                ? \sprintf(
                    "\n\nThe supervisor exited without recording the command's final status. Workload outcome is unknown; do not blindly rerun it.\nPID: %d\nRetained log: %s\nInspect the retained log with read. Foreground records are not exposed by bg_status.",
                    $pid,
                    $savedPath,
                )
                : \sprintf(
                    "\n\nThe supervisor exited without recording the command's final status. Workload outcome is unknown; do not blindly rerun it.\nPID: %d\nNo retained command log was available.",
                    $pid,
                );
        }
        $text = \sprintf("%s\n\nOutput:\n%s%s", $failure, $output, $logHint);
        throw new ToolCallException($text);
    }

    private function backgroundAcknowledgment(int $pid): string
    {
        return \sprintf(
            "Command moved to background.\nPID: %d\n\nYou will be notified when the process finishes. Use bg_status log pid=%d to inspect output or bg_status stop pid=%d to stop it.",
            $pid,
            $pid,
            $pid,
        );
    }
}
