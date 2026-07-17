<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Compaction;

use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Contract\Compaction\CompactionSummarizationOutcome;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationOptions;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Shared no-tools compaction model invocation used by the async worker and
 * synchronous in-memory snapshot compaction.
 */
final readonly class CompactionSummarizationInvoker
{
    public function __construct(
        private PlatformInterface $platform,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<AgentMessage>   $summarizationMessages
     * @param array<string, mixed> $modelOptions
     */
    public function invoke(
        string $runId,
        int $turnNo,
        string $stepId,
        string $model,
        array $summarizationMessages,
        array $modelOptions = [],
        string $trigger = 'manual',
    ): CompactionSummarizationOutcome {
        $startedAt = hrtime(true);

        RunLogContext::enter([
            'event_type' => 'compaction.request.started',
            'model' => $model,
            'trigger' => $trigger,
        ]);

        try {
            $invoke = fn (): PlatformInvocationResult => $this->platform->invoke(new ModelInvocationRequest(
                model: $model,
                input: new ModelInvocationInput(
                    runId: $runId,
                    turnNo: $turnNo,
                    stepId: $stepId,
                    messages: $summarizationMessages,
                ),
                options: new ModelInvocationOptions(
                    toolsEnabled: false,
                    extraOptions: $modelOptions,
                    streamObserverEnabled: false,
                ),
            ));

            $response = null === $this->tracer
                ? $invoke()
                : $this->tracer->inSpan('compaction.call', [
                    'run_id' => $runId,
                    'turn_no' => $turnNo,
                    'step_id' => $stepId,
                    'model' => $model,
                ], $invoke)
            ;

            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->metrics?->recordLlmLatency($durationMs, null !== $response->error);

            if (null !== $response->error) {
                $this->logger->warning('compaction.request.failed', [
                    'duration_ms' => round($durationMs, 3),
                    'event_type' => 'compaction.request.failed',
                    'error_type' => $response->error['type'] ?? 'unknown',
                    'error_message' => mb_substr($response->error['message'] ?? 'Unknown error', 0, 500),
                ]);

                return new CompactionSummarizationOutcome(summaryText: null, error: $response->error);
            }

            $this->logger->info('compaction.request.completed', [
                'duration_ms' => round($durationMs, 3),
                'event_type' => 'compaction.request.completed',
            ]);

            $summaryText = null;
            if (null !== $response->assistantMessage) {
                $summaryText = $response->assistantMessage->asText();
            }

            return new CompactionSummarizationOutcome(summaryText: $summaryText, error: null);
        } catch (\Throwable $exception) {
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->metrics?->recordLlmLatency($durationMs, true);

            $rawMessage = $exception->getMessage();
            $cappedMessage = mb_substr($rawMessage, 0, 200);

            $this->logger->warning('compaction.request.failed', [
                'duration_ms' => round($durationMs, 3),
                'event_type' => 'compaction.request.failed',
                'error_type' => $exception::class,
                'error_message' => mb_substr($rawMessage, 0, 500),
            ]);

            return new CompactionSummarizationOutcome(
                summaryText: null,
                error: [
                    'type' => $exception::class,
                    'message' => $cappedMessage,
                    'user_message' => \sprintf(
                        'Compaction failed: The summarization model call could not be completed. %s',
                        '' !== $cappedMessage ? '(Detail: '.$cappedMessage.')' : '',
                    ),
                ],
            );
        } finally {
            RunLogContext::leave();
        }
    }
}
