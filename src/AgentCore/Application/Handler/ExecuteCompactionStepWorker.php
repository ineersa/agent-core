<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Application\Compaction\CompactionSummarizationInvoker;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Async worker for compaction summarization model invocations.
 *
 * Delegates the no-tools platform call to {@see CompactionSummarizationInvoker}
 * (shared with synchronous snapshot compaction) and dispatches a
 * {@see CompactionStepResult} back to the command bus for result handling
 * and state mutation.
 */
final readonly class ExecuteCompactionStepWorker
{
    public function __construct(
        private CompactionSummarizationInvoker $summarizationInvoker,
        private MessageBusInterface $commandBus,
        private ?RunTracer $tracer = null,
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
        $model = $message->model;
        $summarizationMessages = $this->deserializeMessages($message->summarizationMessages);

        $outcome = $this->summarizationInvoker->invoke(
            runId: $message->runId(),
            turnNo: $message->turnNo(),
            stepId: $message->stepId(),
            model: $model,
            summarizationMessages: $summarizationMessages,
            modelOptions: $message->modelOptions,
            trigger: $message->trigger,
        );

        return new CompactionStepResult(
            runId: $message->runId(),
            turnNo: $message->turnNo(),
            stepId: $message->stepId(),
            attempt: $message->attempt(),
            idempotencyKey: $message->idempotencyKey(),
            summaryText: $outcome->summaryText,
            error: $outcome->error,
            retainedTailMessages: $message->retainedTailMessages,
            messagesCompacted: $message->messagesCompacted,
            messagesRetained: $message->messagesRetained,
            firstRetainedIndex: $message->firstRetainedIndex,
            tokenEstimateBefore: $message->tokenEstimateBefore,
            trigger: $message->trigger,
            continueAfterCompaction: $message->continueAfterCompaction,
            model: $model,
            modelOptions: $message->modelOptions,
            hookMetadata: $message->hookMetadata,
        );
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
