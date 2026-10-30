<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\MalformedToolResultException;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
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
 * Top-level model Bash uses owner-prepared native identity and reports finished
 * output or background acknowledgment through DeferredToolCompletionOutcome with
 * an optional CompleteDeferredToolCall. Nested code_mode Bash stays local and
 * unpersisted. Direct !shell uses prepared paths but returns an ordinary result.
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
        private readonly DeferredToolCompletionRepositoryInterface $deferred,
        private readonly NormalizerInterface $normalizer,
        private readonly BashToolConfig $config = new BashToolConfig(),
    ) {
    }

    /**
     * @return string|array{content: string, bash_native: array<string, mixed>}|DeferredToolCompletionOutcome
     */
    public function __invoke(
        #[MapToolArguments]
        BashArgumentsDTO $arguments,
    ): string|array|DeferredToolCompletionOutcome {
        return $this->toolRuntime->run(function () use ($arguments): string|array|DeferredToolCompletionOutcome {
            $command = trim($arguments->command);
            $timeout = $this->resolveTimeout($arguments);
            $context = $this->contextAccessor->current();
            $sessionId = $context?->runId();
            $cancelToken = $context?->cancellationToken();
            $deadline = hrtime(true) + $timeout * 1_000_000_000;

            $topLevelModel = null !== $context?->stepId() && 1 === $this->contextAccessor->depth();
            $toolCallId = $context?->toolCallId();
            $prepared = null;
            $deferredId = null;
            if (null !== $sessionId && null !== $toolCallId && '' !== $toolCallId) {
                $prepared = $this->manager->findPreparedLaunch($sessionId, $toolCallId);
                if (null !== $prepared && $topLevelModel) {
                    $deferredId = $this->deferred->findMetadataByRunAndToolCall($sessionId, $toolCallId)?->deferredId;
                    if (null === $deferredId) {
                        throw new ToolCallException('Owner-prepared Bash deferred correlation is missing.', retryable: false);
                    }
                    $prepared = new PreparedBashLaunchDTO(
                        id: $prepared->id,
                        runId: $prepared->runId,
                        toolCallId: $prepared->toolCallId,
                        command: $prepared->command,
                        logPath: $prepared->logPath,
                        statusPath: $prepared->statusPath,
                        deferredId: $deferredId,
                        backgroundEligible: true,
                        deadlineNanoseconds: $prepared->deadlineNanoseconds,
                    );
                } elseif (null === $prepared && (null !== $context?->stepId() || 1 === $this->contextAccessor->depth())) {
                    // Top-level model Bash and direct shell must be owner-prepared.
                    // Nested code_mode Bash has depth > 1 and no prepared row.
                    if ($topLevelModel || null === $context?->stepId()) {
                        throw new ToolCallException('Owner-prepared native Bash identity is missing for this invocation.', retryable: false);
                    }
                }
            }
            if ($arguments->backgrounded && (null === $prepared || null === $prepared->deferredId)) {
                throw new ToolCallException('Background launch requires a top-level Bash tool invocation.', retryable: false);
            }

            $startResult = $this->manager->start(
                $command,
                $sessionId,
                $prepared?->toolCallId ?? ($topLevelModel ? $toolCallId : null),
                $deadline,
                backgroundRequested: $arguments->backgrounded,
                preparedRecordId: $prepared?->id,
                preparedLogPath: $prepared?->logPath,
                preparedStatusPath: $prepared?->statusPath,
                deferredId: $prepared?->deferredId,
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

            while (true) {
                if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                    $this->manager->stopByStatusPath($statusPath, $sessionId);
                    $this->logger->info('bash_tool.cancelled', [
                        'component' => 'tool.bash',
                        'event_type' => 'bash_tool.cancelled',
                        'process_pid' => $pid,
                    ]);

                    return '';
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

                    return $this->finishText($prepared, $startResult->id, $sessionId, $pid, $statusPath, $logPath, $text, timedOut: true);
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
                    return $this->handleFinished($record, $prepared, $startResult->id, $sessionId);
                }

                if ($record->backgroundRequested) {
                    if (!$this->manager->tryMarkBackgroundedForStatusPath($statusPath, $sessionId)) {
                        continue;
                    }
                    $text = \sprintf(
                        "Command moved to background.\nPID: %d\nLog: %s\n\nYou will be notified when the process finishes. Use bg_status log pid=%d to inspect output or bg_status stop pid=%d to stop it.",
                        $pid,
                        $logPath,
                        $pid,
                        $pid,
                    );

                    return $this->finishBackgroundAck($prepared, $startResult->id, $sessionId, $record, $text);
                }

                delay($this->config->pollIntervalMicros / 1_000_000);
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
                "or use an explicit character window with a text encoding if you need readable text.",
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
    ): string|array|DeferredToolCompletionOutcome {
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
            return $this->finishText($prepared, $recordId, $sessionId, $pid, $entity->statusPath, $entity->logPath, $output, observation: $entity);
        }

        $failure = null !== $exitCode
            ? \sprintf('Command failed with exit code %d.', $exitCode)
            : 'Command supervision ended without an exit status.';
        $logHint = null === $exitCode && '' !== $entity->logPath
            ? \sprintf("\n\nThe supervisor exited without recording the command's final status. Workload outcome is unknown; do not blindly rerun it.\nLog: %s\nStatus file: %s\nPID: %d\nInspect these files with read. Foreground records are not exposed by bg_status.", $entity->logPath, $entity->statusPath, $pid)
            : '';
        $text = \sprintf("%s\n\nOutput:\n%s%s", $failure, $output, $logHint);
        if (null !== $prepared?->deferredId && null !== $recordId && null !== $sessionId) {
            return $this->deferredCompletion(
                $prepared,
                $recordId,
                $sessionId,
                $entity,
                $text,
                isError: true,
                finished: true,
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
    ): string|array|DeferredToolCompletionOutcome {
        if (null === $prepared?->deferredId || null === $recordId || null === $sessionId) {
            return $text;
        }

        return $this->deferredCompletion(
            $prepared,
            $recordId,
            $sessionId,
            $entity,
            $text,
            isError: false,
            finished: false,
            accepted: true,
        );
    }

    private function finishText(
        ?PreparedBashLaunchDTO $prepared,
        ?int $recordId,
        ?string $sessionId,
        int $pid,
        string $statusPath,
        string $logPath,
        string $text,
        bool $timedOut = false,
        ?NativeBashObservationDTO $observation = null,
    ): string|array|DeferredToolCompletionOutcome {
        if (null === $prepared?->deferredId || null === $recordId || null === $sessionId) {
            if (null !== $prepared && null !== $recordId && null !== $sessionId) {
                $entity = $observation ?? new NativeBashObservationDTO(
                    pid: $pid,
                    pgid: null,
                    sessionId: $sessionId,
                    command: $prepared->command,
                    logPath: $logPath,
                    statusPath: $statusPath,
                    startedAt: Clock::get()->now(),
                    finishedAt: Clock::get()->now(),
                    exitCode: $timedOut ? -1 : 0,
                    stoppedByUser: $timedOut,
                    accepted: false,
                    toolCallId: $prepared->toolCallId,
                    backgroundRequested: false,
                    status: $timedOut ? BackgroundProcessStatusEnum::Stopped : BackgroundProcessStatusEnum::Finished,
                    deadlineNanoseconds: null,
                );
                $native = new BashNativeCompletionMetadataDTO(
                    recordId: $recordId,
                    runId: $sessionId,
                    toolCallId: $prepared->toolCallId,
                    pid: $entity->pid,
                    pgid: $entity->pgid,
                    startedAt: $entity->startedAt,
                    deadlineNanoseconds: null,
                    acceptedAt: null,
                    finishedAt: $entity->finishedAt,
                    exitCode: $entity->exitCode,
                    status: $entity->status,
                    stoppedByUser: $entity->stoppedByUser,
                );

                /** @var array<string, mixed> $normalized */
                $normalized = $this->normalizer->normalize($native);

                return [
                    'content' => $text,
                    'bash_native' => $normalized,
                ];
            }

            return $text;
        }
        $entity = $observation ?? new NativeBashObservationDTO(
            pid: $pid,
            pgid: null,
            sessionId: $sessionId,
            command: $prepared->command,
            logPath: $logPath,
            statusPath: $statusPath,
            startedAt: Clock::get()->now(),
            finishedAt: Clock::get()->now(),
            exitCode: $timedOut ? -1 : 0,
            stoppedByUser: $timedOut,
            accepted: false,
            toolCallId: $prepared->toolCallId,
            backgroundRequested: false,
            status: $timedOut ? BackgroundProcessStatusEnum::Stopped : BackgroundProcessStatusEnum::Finished,
            deadlineNanoseconds: $prepared->deadlineNanoseconds,
        );

        return $this->deferredCompletion(
            $prepared,
            $recordId,
            $sessionId,
            $entity,
            $text,
            isError: $timedOut,
            finished: true,
        );
    }

    private function deferredCompletion(
        PreparedBashLaunchDTO $prepared,
        int $recordId,
        string $sessionId,
        NativeBashObservationDTO $entity,
        string $text,
        bool $isError,
        bool $finished,
        bool $accepted = false,
    ): DeferredToolCompletionOutcome {
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

        return new DeferredToolCompletionOutcome(
            $prepared->deferredId,
            new CompleteDeferredToolCall(
                $prepared->deferredId,
                [['type' => 'text', 'text' => $text]],
                ['bash_native' => $normalized],
                $isError,
            ),
        );
    }
}
