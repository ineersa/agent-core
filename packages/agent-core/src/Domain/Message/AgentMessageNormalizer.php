<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Result\ToolCall;

final readonly class AgentMessageNormalizer
{
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
