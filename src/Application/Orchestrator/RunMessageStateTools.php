<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Orchestrator;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;

final readonly class RunMessageStateTools
{
    /**
     * @param array<string, mixed> $overrides
     */
    public function copyState(RunState $state, array $overrides = []): RunState
    {
        return new RunState(
            runId: $overrides['runId'] ?? $state->runId,
            status: $overrides['status'] ?? $state->status,
            version: $overrides['version'] ?? $state->version,
            turnNo: $overrides['turnNo'] ?? $state->turnNo,
            lastSeq: $overrides['lastSeq'] ?? $state->lastSeq,
            isStreaming: $overrides['isStreaming'] ?? $state->isStreaming,
            streamingMessage: \array_key_exists('streamingMessage', $overrides)
                ? $overrides['streamingMessage']
                : $state->streamingMessage,
            pendingToolCalls: $overrides['pendingToolCalls'] ?? $state->pendingToolCalls,
            errorMessage: \array_key_exists('errorMessage', $overrides)
                ? $overrides['errorMessage']
                : $state->errorMessage,
            messages: $overrides['messages'] ?? $state->messages,
            activeStepId: \array_key_exists('activeStepId', $overrides)
                ? $overrides['activeStepId']
                : $state->activeStepId,
            retryableFailure: $overrides['retryableFailure'] ?? $state->retryableFailure,
        );
    }

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

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<AgentMessage>
     */
    public function messagesFromPayload(array $payload): array
    {
        $serializedMessages = $payload['messages'] ?? null;
        if (!\is_array($serializedMessages)) {
            return [];
        }

        $messages = [];

        foreach ($serializedMessages as $serializedMessage) {
            if (!\is_array($serializedMessage)) {
                continue;
            }

            $message = AgentMessage::fromPayload($serializedMessage);
            if (null === $message) {
                continue;
            }

            $messages[] = $message;
        }

        return $messages;
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
        return $this->copyState($state, [
            'version' => $state->version + 1,
            'lastSeq' => $state->lastSeq + $eventCount,
        ]);
    }

    /**
     * @param array<string, mixed> $assistantMessage
     */
    public function assistantMessage(array $assistantMessage): AgentMessage
    {
        $content = [];
        $rawContent = $assistantMessage['content'] ?? [];

        if (\is_string($rawContent)) {
            $content[] = [
                'type' => 'text',
                'text' => $rawContent,
            ];
        }

        if (\is_array($rawContent)) {
            foreach ($rawContent as $contentPart) {
                if (!\is_array($contentPart)) {
                    continue;
                }

                $content[] = $contentPart;
            }
        }

        $metadata = [];
        if (\is_array($assistantMessage['tool_calls'] ?? null)) {
            $metadata['tool_calls'] = $assistantMessage['tool_calls'];
        }

        return new AgentMessage(
            role: \is_string($assistantMessage['role'] ?? null) ? $assistantMessage['role'] : 'assistant',
            content: $content,
            metadata: $metadata,
        );
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
     * @param array<string, mixed> $assistantMessage
     *
     * @return list<array{id: string, name: string, args: array<string, mixed>, order_index: int, tool_idempotency_key: string|null}>
     */
    public function extractToolCalls(array $assistantMessage): array
    {
        $rawToolCalls = $assistantMessage['tool_calls'] ?? null;
        if (!\is_array($rawToolCalls)) {
            return [];
        }

        $toolCalls = [];

        foreach ($rawToolCalls as $index => $rawToolCall) {
            if (!\is_array($rawToolCall)) {
                continue;
            }

            $id = $rawToolCall['id'] ?? null;
            $name = $rawToolCall['name'] ?? null;

            if (!\is_string($id) || !\is_string($name)) {
                continue;
            }

            $toolCalls[] = [
                'id' => $id,
                'name' => $name,
                'args' => \is_array($rawToolCall['arguments'] ?? null) ? $rawToolCall['arguments'] : [],
                'order_index' => \is_int($rawToolCall['order_index'] ?? null) ? $rawToolCall['order_index'] : $index,
                'tool_idempotency_key' => \is_string($rawToolCall['tool_idempotency_key'] ?? null) ? $rawToolCall['tool_idempotency_key'] : null,
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
}
