<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;

/**
 * Bounded exact payload for the one compaction worker currently in flight.
 * It is cleared when that compaction reaches a terminal lifecycle event.
 */
final readonly class CurrentCompactionExecutionDTO
{
    public function __construct(public ExecuteCompactionStep $request)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromStartedEvent(string $runId, array $payload): ?self
    {
        $worker = $payload['worker_request'] ?? null;
        if (!\is_array($worker)) {
            return null;
        }

        $stepId = $payload['step_id'] ?? null;
        $attempt = $payload['operation_attempt'] ?? null;
        $key = $payload['operation_idempotency_key'] ?? null;
        $turnNo = $payload['turn_no'] ?? null;
        $model = $worker['model'] ?? null;
        $summarizationMessages = self::messages($worker['summarization_messages'] ?? null);
        $retainedTailMessages = self::messages($worker['retained_tail_messages'] ?? null);

        if (!\is_int($turnNo) || !\is_string($stepId) || !\is_int($attempt) || !\is_string($key) || !\is_string($model)
            || null === $summarizationMessages || null === $retainedTailMessages
            || !\is_array($worker['model_options'] ?? null)
            || !\is_int($worker['messages_compacted'] ?? null)
            || !\is_int($worker['messages_retained'] ?? null)
            || !\is_int($worker['first_retained_index'] ?? null)
            || !\is_int($worker['token_estimate_before'] ?? null)
            || !\is_string($worker['trigger'] ?? null)
            || !\is_bool($worker['continue_after_compaction'] ?? null)
            || (null !== ($worker['hook_metadata'] ?? null) && !\is_array($worker['hook_metadata']))) {
            return null;
        }

        return new self(new ExecuteCompactionStep(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: $attempt,
            idempotencyKey: $key,
            model: $model,
            modelOptions: $worker['model_options'],
            summarizationMessages: $summarizationMessages,
            retainedTailMessages: $retainedTailMessages,
            messagesCompacted: $worker['messages_compacted'],
            messagesRetained: $worker['messages_retained'],
            firstRetainedIndex: $worker['first_retained_index'],
            tokenEstimateBefore: $worker['token_estimate_before'],
            trigger: $worker['trigger'],
            continueAfterCompaction: $worker['continue_after_compaction'],
            hookMetadata: $worker['hook_metadata'] ?? null,
        ));
    }

    /** @return array<string, mixed> */
    public function eventPayload(): array
    {
        $request = $this->request;

        return [
            'worker_request' => [
                'model' => $request->model,
                'model_options' => $request->modelOptions,
                'summarization_messages' => array_map(static fn (AgentMessage $message): array => $message->toArray(), $request->summarizationMessages),
                'retained_tail_messages' => array_map(static fn (AgentMessage $message): array => $message->toArray(), $request->retainedTailMessages),
                'messages_compacted' => $request->messagesCompacted,
                'messages_retained' => $request->messagesRetained,
                'first_retained_index' => $request->firstRetainedIndex,
                'token_estimate_before' => $request->tokenEstimateBefore,
                'trigger' => $request->trigger,
                'continue_after_compaction' => $request->continueAfterCompaction,
                'hook_metadata' => $request->hookMetadata,
            ],
        ];
    }

    /** @return list<AgentMessage>|null */
    private static function messages(mixed $raw): ?array
    {
        if (!\is_array($raw)) {
            return null;
        }

        $messages = [];
        foreach ($raw as $item) {
            if (!\is_array($item)) {
                return null;
            }
            $message = AgentMessage::fromPayload($item);
            if (null === $message) {
                return null;
            }
            $messages[] = $message;
        }

        return $messages;
    }
}
