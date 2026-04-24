<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Orchestrator;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Result\ToolCall;

final readonly class RunMessageStateTools
{
    /**
     * @param array<string, mixed> $payload
     */
    public function event(string $runId, int $seq, int $turnNo, string $type, array $payload = []): RunEvent
    {
        return new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
        );
    }

    /**
     * @param list<array{type: string, payload: array<string, mixed>, turn_no?: int}> $eventSpecs
     *
     * @return list<RunEvent>
     */
    public function eventsFromSpecs(string $runId, int $turnNo, int $startSeq, array $eventSpecs): array
    {
        $events = [];
        $seq = $startSeq;

        foreach ($eventSpecs as $eventSpec) {
            $eventTurnNo = \is_int($eventSpec['turn_no'] ?? null)
                ? $eventSpec['turn_no']
                : $turnNo;

            $events[] = $this->event(
                runId: $runId,
                seq: $seq,
                turnNo: $eventTurnNo,
                type: $eventSpec['type'],
                payload: $eventSpec['payload'],
            );

            ++$seq;
        }

        return $events;
    }

    public function isStaleResult(RunState $state, int $turnNo, string $stepId): bool
    {
        if ($state->turnNo !== $turnNo) {
            return true;
        }

        return null !== $state->activeStepId && $state->activeStepId !== $stepId;
    }

    public function incrementStateVersion(RunState $state, int $eventCount): RunState
    {
        return new RunState(
            runId: $state->runId,
            status: $state->status,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + $eventCount,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
            retryableFailure: $state->retryableFailure,
        );
    }

    public function assistantMessage(AssistantMessage $assistantMessage): AgentMessage
    {
        $content = [];
        if (null !== $assistantMessage->getContent()) {
            $content[] = [
                'type' => 'text',
                'text' => $assistantMessage->getContent(),
            ];
        }

        $metadata = [];
        $toolCalls = $this->normalizeToolCalls($assistantMessage->getToolCalls());
        if ([] !== $toolCalls) {
            $metadata['tool_calls'] = $toolCalls;
        }

        $details = array_filter([
            'thinking' => $assistantMessage->getThinkingContent(),
            'thinking_signature' => $assistantMessage->getThinkingSignature(),
        ], static fn (mixed $value): bool => null !== $value);

        return new AgentMessage(
            role: 'assistant',
            content: $content,
            details: [] !== $details ? $details : null,
            metadata: $metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function assistantMessagePayload(AssistantMessage $assistantMessage): array
    {
        $payload = [
            'role' => 'assistant',
            'content' => null === $assistantMessage->getContent()
                ? null
                : [[
                    'type' => 'text',
                    'text' => $assistantMessage->getContent(),
                ]],
        ];

        $toolCalls = $this->normalizeToolCalls($assistantMessage->getToolCalls());
        if ([] !== $toolCalls) {
            $payload['tool_calls'] = $toolCalls;
        }

        $details = array_filter([
            'thinking' => $assistantMessage->getThinkingContent(),
            'thinking_signature' => $assistantMessage->getThinkingSignature(),
        ], static fn (mixed $value): bool => null !== $value);

        if ([] !== $details) {
            $payload['details'] = $details;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function humanResponseMessage(array $payload): ?AgentMessage
    {
        if (!\array_key_exists('answer', $payload)) {
            return null;
        }

        $content = json_encode([
            'question_id' => \is_string($payload['question_id'] ?? null) ? $payload['question_id'] : null,
            'answer' => $payload['answer'],
        ]);

        if (false === $content) {
            $content = '{}';
        }

        return new AgentMessage(
            role: 'user',
            content: [[
                'type' => 'text',
                'text' => $content,
            ]],
            metadata: \is_string($payload['question_id'] ?? null)
                ? ['question_id' => $payload['question_id']]
                : [],
        );
    }

    public function toolMessage(ToolCallResult $result): AgentMessage
    {
        $text = json_encode([
            'is_error' => $result->isError,
            'result' => $result->result,
            'error' => $result->error,
        ]);

        if (false === $text) {
            $text = '{}';
        }

        $toolName = \is_string($result->result['tool_name'] ?? null) ? $result->result['tool_name'] : null;

        return new AgentMessage(
            role: 'tool',
            content: [[
                'type' => 'text',
                'text' => $text,
            ]],
            toolCallId: $result->toolCallId,
            toolName: $toolName,
            details: $result->result,
            isError: $result->isError,
            metadata: [
                'order_index' => $result->orderIndex,
            ],
        );
    }

    /**
     * @return list<array{id: string, name: string, args: array<string, mixed>, order_index: int, tool_idempotency_key: string|null}>
     */
    public function extractToolCalls(AssistantMessage $assistantMessage): array
    {
        $toolCalls = [];

        foreach ($assistantMessage->getToolCalls() ?? [] as $index => $toolCall) {
            if (!$toolCall instanceof ToolCall) {
                continue;
            }

            $toolCalls[] = [
                'id' => $toolCall->getId(),
                'name' => $toolCall->getName(),
                'args' => $toolCall->getArguments(),
                'order_index' => $index,
                'tool_idempotency_key' => null,
            ];
        }

        return $toolCalls;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function interruptPayloadFromToolResult(ToolCallResult $result): ?array
    {
        if ($result->isError || !\is_array($result->result)) {
            return null;
        }

        $details = \is_array($result->result['details'] ?? null)
            ? $result->result['details']
            : [];

        $interrupt = null;

        if ('interrupt' === ($details['kind'] ?? null)) {
            $interrupt = $details;
        }

        if (null === $interrupt && 'interrupt' === ($result->result['kind'] ?? null)) {
            $interrupt = $result->result;
        }

        if (null === $interrupt) {
            return null;
        }

        $questionId = \is_string($interrupt['question_id'] ?? null)
            ? $interrupt['question_id']
            : $result->toolCallId;

        $payload = [
            'tool_call_id' => $result->toolCallId,
            'tool_name' => \is_string($result->result['tool_name'] ?? null) ? $result->result['tool_name'] : null,
            'question_id' => $questionId,
            'prompt' => \is_string($interrupt['prompt'] ?? null) ? $interrupt['prompt'] : 'Human input required.',
            'schema' => \is_array($interrupt['schema'] ?? null) ? $interrupt['schema'] : ['type' => 'string'],
        ];

        return array_filter($payload, static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @param ?list<ToolCall> $toolCalls
     *
     * @return list<array{id: string, name: string, arguments: array<string, mixed>, order_index: int}>
     */
    private function normalizeToolCalls(?array $toolCalls): array
    {
        if (null === $toolCalls) {
            return [];
        }

        $normalized = [];

        foreach ($toolCalls as $index => $toolCall) {
            if (!$toolCall instanceof ToolCall) {
                continue;
            }

            $normalized[] = [
                'id' => $toolCall->getId(),
                'name' => $toolCall->getName(),
                'arguments' => $toolCall->getArguments(),
                'order_index' => $index,
            ];
        }

        return $normalized;
    }
}
