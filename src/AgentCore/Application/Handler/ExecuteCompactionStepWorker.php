<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationOptions;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Async worker for compaction summarization model invocations.
 *
 * Calls PlatformInterface with direct summarization messages, explicit
 * no-tools (toolsEnabled: false), a resolved compaction model, and a
 * model-option override.  Stream observer notifications are suppressed
 * (streamObserverEnabled: false) since compaction has no interactive
 * consumer for streaming deltas — only the final result matters.
 *
 * Dispatches a {@see CompactionStepResult} back to the command bus for
 * result handling and state mutation.
 */
final readonly class ExecuteCompactionStepWorker
{
    public function __construct(
        private PlatformInterface $platform,
        private MessageBusInterface $commandBus,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[AsMessageHandler(bus: 'agent.execution.bus')]
    public function __invoke(ExecuteCompactionStep $message): void
    {
        RunLogContext::enter([
            'run_id' => $message->runId(),
            'session_id' => $message->runId(),
            'component' => 'compaction',
            'queue' => 'agent.execution.bus',
            'worker' => 'compaction',
        ]);

        try {
            $execute = function () use ($message): void {
                $result = $this->execute($message);

                try {
                    $this->commandBus->dispatch($result);
                } catch (ExceptionInterface $exception) {
                    throw new \RuntimeException('Failed to dispatch compaction result to command bus.', previous: $exception);
                }
            };

            if (null === $this->tracer) {
                $execute();

                return;
            }

            $this->tracer->inSpan('turn.execution.compaction_worker', [
                'run_id' => $message->runId(),
                'turn_no' => $message->turnNo(),
                'step_id' => $message->stepId(),
                'worker' => 'compaction',
                'model' => $message->model,
            ], $execute, root: true);
        } finally {
            RunLogContext::leave();
        }
    }

    private function execute(ExecuteCompactionStep $message): CompactionStepResult
    {
        $startedAt = hrtime(true);
        $model = $message->model;

        // Deserialise summarization messages from transport-safe array shapes.
        $summarizationMessages = $this->deserializeMessages($message->summarizationMessages);

        RunLogContext::enter([
            'event_type' => 'compaction.request.started',
            'model' => $model,
            'trigger' => $message->trigger,
        ]);

        try {
            $invoke = fn (): PlatformInvocationResult => $this->platform->invoke(new ModelInvocationRequest(
                model: $model,
                input: new ModelInvocationInput(
                    runId: $message->runId(),
                    turnNo: $message->turnNo(),
                    stepId: $message->stepId(),
                    messages: $summarizationMessages,
                    // toolsRef is intentionally null — the toolsEnabled:false
                    // flag in ModelInvocationOptions below explicitly disables
                    // all tool resolution, providing a stronger guarantee than
                    // relying on null meaning "no tools".
                ),
                options: new ModelInvocationOptions(
                    toolsEnabled: false,
                    extraOptions: $message->modelOptions,
                    streamObserverEnabled: false,
                ),
            ));

            $response = null === $this->tracer
                ? $invoke()
                : $this->tracer->inSpan('compaction.call', [
                    'run_id' => $message->runId(),
                    'turn_no' => $message->turnNo(),
                    'step_id' => $message->stepId(),
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
            } else {
                $this->logger->info('compaction.request.completed', [
                    'duration_ms' => round($durationMs, 3),
                    'event_type' => 'compaction.request.completed',
                ]);
            }

            $summaryText = null;
            if (null !== $response->assistantMessage) {
                $summaryText = $response->assistantMessage->asText();
            }

            return new CompactionStepResult(
                runId: $message->runId(),
                turnNo: $message->turnNo(),
                stepId: $message->stepId(),
                attempt: $message->attempt(),
                idempotencyKey: $message->idempotencyKey(),
                summaryText: $summaryText,
                error: $response->error,
                retainedTailMessages: $message->retainedTailMessages,
                messagesCompacted: $message->messagesCompacted,
                messagesRetained: $message->messagesRetained,
                firstRetainedIndex: $message->firstRetainedIndex,
                tokenEstimateBefore: $message->tokenEstimateBefore,
                trigger: $message->trigger,
                model: $model,
                modelOptions: $message->modelOptions,
                hookMetadata: $message->hookMetadata,
            );
        } catch (\Throwable $exception) {
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->metrics?->recordLlmLatency($durationMs, true);

            $rawMessage = $exception->getMessage();

            $this->logger->warning('compaction.request.failed', [
                'duration_ms' => round($durationMs, 3),
                'event_type' => 'compaction.request.failed',
                'error_type' => $exception::class,
                'error_message' => mb_substr($rawMessage, 0, 500),
            ]);

            // Build a safe error array for the context_compaction_failed payload.
            // The raw exception message may contain sensitive data (URLs, prompts);
            // the sanitised user_message is surfaced in the TUI while the full
            // detail is logged above.
            $cappedMessage = mb_substr($rawMessage, 0, 200);

            return new CompactionStepResult(
                runId: $message->runId(),
                turnNo: $message->turnNo(),
                stepId: $message->stepId(),
                attempt: $message->attempt(),
                idempotencyKey: $message->idempotencyKey(),
                summaryText: null,
                error: [
                    'type' => $exception::class,
                    'message' => $cappedMessage,
                    'user_message' => \sprintf('Compaction failed: The summarization model call could not be completed. %s', '' !== $cappedMessage ? '(Detail: '.$cappedMessage.')' : ''),
                ],
                retainedTailMessages: $message->retainedTailMessages,
                messagesCompacted: $message->messagesCompacted,
                messagesRetained: $message->messagesRetained,
                firstRetainedIndex: $message->firstRetainedIndex,
                tokenEstimateBefore: $message->tokenEstimateBefore,
                trigger: $message->trigger,
                model: $model,
                modelOptions: $message->modelOptions,
                hookMetadata: $message->hookMetadata,
            );
        } finally {
            RunLogContext::leave();
        }
    }

    /**
     * Deserialise AgentMessage array shapes from transport-safe array payloads.
     *
     * @param list<array<string, mixed>> $rawMessages
     *
     * @return list<AgentMessage>
     */
    private function deserializeMessages(array $rawMessages): array
    {
        $messages = [];

        foreach ($rawMessages as $raw) {
            if (!\is_array($raw)) {
                continue;
            }

            $msg = AgentMessage::fromPayload($raw);
            if (null !== $msg) {
                $messages[] = $msg;
            }
        }

        return $messages;
    }
}
