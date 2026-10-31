<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\MalformedToolResultException;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Config\BashToolConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tool\Arguments\BashArgumentsDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\BashNativeCompletionMetadataDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\NativeBashObservationDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\PreparedBashLaunchDTO;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\MapToolArguments;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

use function Amp\delay;

/**
 * Execute a shell command with foreground supervision via BackgroundProcessManager.
 *
 * Top-level model Bash and direct !shell use owner-prepared native identity and
 * return an ordinary {@see ToolResult} with visible content plus private
 * bash_native details. Nested code_mode Bash stays local plain text.
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
        private readonly NormalizerInterface $normalizer,
        private readonly BashToolConfig $config = new BashToolConfig(),
    ) {
    }

    public function __invoke(
        #[MapToolArguments]
        BashArgumentsDTO $arguments,
    ): string|ToolResult {
        return $this->toolRuntime->run(function () use ($arguments): string|ToolResult {
            $command = trim($arguments->command);
            $timeout = $this->resolveTimeout($arguments);
            $context = $this->contextAccessor->current();
            $sessionId = $context?->runId();
            $cancelToken = $context?->cancellationToken();
            $deadline = hrtime(true) + $timeout * 1_000_000_000;

            $topLevelModel = null !== $context?->stepId() && 1 === $this->contextAccessor->depth();
            $toolCallId = $context?->toolCallId();
            $prepared = null;
            if (null !== $sessionId && null !== $toolCallId && '' !== $toolCallId) {
                $turnNo = $context?->turnNo();
                $stepId = $context?->stepId();
                $attempt = $context?->attempt();
                $idempotencyKey = $context?->idempotencyKey();
                if (null !== $turnNo && null !== $stepId && '' !== $stepId && null !== $attempt && null !== $idempotencyKey && '' !== $idempotencyKey) {
                    $prepared = $this->manager->findPreparedLaunch(
                        $sessionId,
                        $toolCallId,
                        $turnNo,
                        $stepId,
                        $attempt,
                        $idempotencyKey,
                    );
                }
                if (null === $prepared && (null !== $context?->stepId() || 1 === $this->contextAccessor->depth())) {
                    // Top-level model Bash and direct shell must be owner-prepared.
                    // Nested code_mode Bash has depth > 1 and no prepared row.
                    if ($topLevelModel || null === $context?->stepId()) {
                        throw new ToolCallException('Owner-prepared native Bash identity is missing for this invocation.', retryable: false);
                    }
                }
            }
            if ($arguments->backgrounded && (null === $prepared || !$topLevelModel)) {
                throw new ToolCallException('Background launch requires a top-level Bash tool invocation.', retryable: false);
            }

            $startResult = $this->manager->start(
                $command,
                $sessionId,
                null !== $prepared ? $prepared->toolCallId : ($topLevelModel ? $toolCallId : null),
                $deadline,
                backgroundRequested: $arguments->backgrounded,
                preparedRecordId: $prepared?->id,
                preparedLogPath: $prepared?->logPath,
                preparedStatusPath: $prepared?->statusPath,
            );
            $pid = $startResult->pid;
            $statusPath = $startResult->statusPath;
            $logPath = $startResult->logPath;

            $this->logger->info('bash_tool.started', [
                'component' => 'tool.bash',
                'event_type' => 'bash_tool.started',
                'process_pid' => $pid,
                'status_path' => $statusPath,
                'log_path' => $logPath,
                'session_id' => $sessionId ?? 'none',
                'record_id' => $startResult->id,
            ]);

            // Keep the native handle through output/metadata consumption, then
            // release once at this invocation boundary — including when output
            // reading throws (invalid UTF-8). Accepted detach already released.
            try {
                while (true) {
                    if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                        return $this->cancelNative($statusPath, $sessionId, $pid, $prepared, $startResult->id, $toolCallId);
                    }

                    if (hrtime(true) > $deadline) {
                        $this->manager->stopByStatusPath($statusPath, $sessionId);
                        $partialOutput = $this->readOutputForNativePaths($logPath, $statusPath);
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
                        $observation = $this->manager->observeNativeByStatusPath($statusPath, $sessionId);
                        if (null === $observation) {
                            throw new ToolCallException(\sprintf('Native Bash launch vanished unexpectedly after timeout (PID %d, status %s).', $pid, $statusPath), retryable: false);
                        }

                        return $this->finishText($prepared, $startResult->id, $sessionId, $observation, $text, timedOut: true, toolCallId: $toolCallId);
                    }

                    $record = $this->manager->observeNativeByStatusPath($statusPath, $sessionId);
                    if (null === $record) {
                        $this->logger->error('bash_tool.native_launch_vanished', [
                            'component' => 'tool.bash',
                            'event_type' => 'bash_tool.native_launch_vanished',
                            'process_pid' => $pid,
                            'status_path' => $statusPath,
                            'session_id' => $sessionId ?? '',
                        ]);

                        throw new ToolCallException(\sprintf('Native Bash launch vanished unexpectedly (PID %d, status %s).', $pid, $statusPath), retryable: false);
                    }

                    if (BackgroundProcessStatusEnum::Running !== $record->status) {
                        return $this->handleFinished($record, $prepared, $startResult->id, $sessionId, $toolCallId);
                    }

                    if ($record->backgroundRequested) {
                        $candidate = $this->manager->resolveBackgroundAcceptance($statusPath, $sessionId);
                        if (null === $candidate) {
                            continue;
                        }
                        // Freshest cancel check after observation/identity resolution
                        // and immediately before the local acceptance transition.
                        if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                            return $this->cancelNative($statusPath, $sessionId, $pid, $prepared, $startResult->id, $toolCallId);
                        }
                        if (!$this->manager->acceptResolvedBackground($candidate)) {
                            continue;
                        }
                        $text = \sprintf(
                            "Command moved to background.\nPID: %d\nLog: %s\n\nYou will be notified when the process finishes. Use bg_status log pid=%d to inspect output or bg_status stop pid=%d to stop it.",
                            $pid,
                            $logPath,
                            $pid,
                            $pid,
                        );

                        return $this->finishBackgroundAck($prepared, $startResult->id, $sessionId, $record, $text, $toolCallId);
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
        ?PreparedBashLaunchDTO $prepared,
        ?int $recordId,
        ?string $toolCallId,
    ): never {
        $this->manager->stopByStatusPath($statusPath, $sessionId);
        $this->logger->info('bash_tool.cancelled', [
            'component' => 'tool.bash',
            'event_type' => 'bash_tool.cancelled',
            'process_pid' => $pid,
            'status_path' => $statusPath,
            'session_id' => $sessionId ?? '',
            'record_id' => $recordId,
            'tool_call_id' => $toolCallId ?? '',
        ]);

        throw new ToolCallException(\sprintf('Bash command cancelled (PID %d).', $pid), retryable: false);
    }

    private function readOutputForNativePaths(string $logPath, string $statusPath): string
    {
        try {
            $result = $this->manager->readLogTailForNativePaths(
                $logPath,
                $statusPath,
                $this->config->logTailChars,
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
                "  read path=%s offset=0 limit=64\n".
                'or use an explicit character window with a text encoding if you need readable text.',
                $result->totalBytes,
                $this->config->logTailChars,
                $logPath,
                $logPath,
            );
        } catch (MalformedToolResultException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ToolCallException(\sprintf('Failed to read Bash output: %s', $exception->getMessage()), retryable: false, previous: $exception);
        }
    }

    private function handleFinished(
        NativeBashObservationDTO $entity,
        ?PreparedBashLaunchDTO $prepared,
        ?int $recordId,
        ?string $sessionId,
        ?string $toolCallId,
    ): string|ToolResult {
        $output = $this->readOutputForNativePaths($entity->logPath, $entity->statusPath);
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
            return $this->finishText($prepared, $recordId, $sessionId, $entity, $output, toolCallId: $toolCallId);
        }

        $failure = null !== $exitCode
            ? \sprintf('Command failed with exit code %d.', $exitCode)
            : 'Command supervision ended without an exit status.';
        $logHint = null === $exitCode && '' !== $entity->logPath
            ? \sprintf("\n\nThe supervisor exited without recording the command's final status. Workload outcome is unknown; do not blindly rerun it.\nLog: %s\nStatus file: %s\nPID: %d\nInspect these files with read. Foreground records are not exposed by bg_status.", $entity->logPath, $entity->statusPath, $pid)
            : '';
        $text = \sprintf("%s\n\nOutput:\n%s%s", $failure, $output, $logHint);
        if (null !== $prepared && null !== $recordId && null !== $sessionId) {
            return $this->ordinaryResult(
                $prepared,
                $recordId,
                $sessionId,
                $entity,
                $text,
                isError: true,
                finished: true,
                accepted: false,
                toolCallId: $toolCallId,
            );
        }

        throw new ToolCallException($text);
    }

    private function finishBackgroundAck(
        ?PreparedBashLaunchDTO $prepared,
        ?int $recordId,
        ?string $sessionId,
        NativeBashObservationDTO $entity,
        string $text,
        ?string $toolCallId,
    ): string|ToolResult {
        if (null === $prepared || null === $recordId || null === $sessionId) {
            return $text;
        }

        return $this->ordinaryResult(
            $prepared,
            $recordId,
            $sessionId,
            $entity,
            $text,
            isError: false,
            finished: false,
            accepted: true,
            toolCallId: $toolCallId,
        );
    }

    private function finishText(
        ?PreparedBashLaunchDTO $prepared,
        ?int $recordId,
        ?string $sessionId,
        NativeBashObservationDTO $observation,
        string $text,
        bool $timedOut = false,
        ?string $toolCallId = null,
    ): string|ToolResult {
        if (null === $prepared || null === $recordId || null === $sessionId) {
            return $text;
        }

        return $this->ordinaryResult(
            $prepared,
            $recordId,
            $sessionId,
            $observation,
            $text,
            isError: $timedOut,
            finished: true,
            accepted: false,
            toolCallId: $toolCallId,
        );
    }

    private function ordinaryResult(
        PreparedBashLaunchDTO $prepared,
        int $recordId,
        string $sessionId,
        NativeBashObservationDTO $entity,
        string $text,
        bool $isError,
        bool $finished,
        bool $accepted,
        ?string $toolCallId,
    ): ToolResult {
        $native = new BashNativeCompletionMetadataDTO(
            recordId: $recordId,
            runId: $sessionId,
            toolCallId: $prepared->toolCallId,
            pid: $entity->pid,
            pgid: $entity->pgid,
            startedAt: $entity->startedAt,
            deadlineNanoseconds: $entity->deadlineNanoseconds ?? $prepared->deadlineNanoseconds,
            acceptedAt: $accepted ? Clock::get()->now() : null,
            finishedAt: $finished ? ($entity->finishedAt ?? Clock::get()->now()) : null,
            exitCode: $finished ? $entity->exitCode : null,
            status: $finished ? $entity->status : null,
            stoppedByUser: $finished ? $entity->stoppedByUser : false,
        );

        /** @var array<string, mixed> $normalized */
        $normalized = $this->normalizer->normalize($native);

        return new ToolResult(
            toolCallId: $toolCallId ?? $prepared->toolCallId,
            toolName: self::NAME,
            content: [['type' => 'text', 'text' => $text]],
            details: ['bash_native' => $normalized],
            isError: $isError,
        );
    }
}
