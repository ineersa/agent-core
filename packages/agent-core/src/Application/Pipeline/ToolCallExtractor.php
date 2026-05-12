<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Result\ToolCall;

final readonly class ToolCallExtractor
{
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
}
