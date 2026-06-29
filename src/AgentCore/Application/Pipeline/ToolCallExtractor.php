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

        foreach ($assistantMessage->getToolCalls() as $index => $toolCall) {
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
     * Extract the interrupt payload from a tool call result using generic passthrough.
     *
     * AgentCore does not enumerate tool-specific fields. It starts from the full
     * interrupt array, preserving every key generically. Only the five core fields
     * (tool_call_id, tool_name, question_id, prompt, schema) receive typed fallbacks;
     * all other fields pass through unchanged. This keeps AgentCore tool-agnostic
     * while richer payloads survive to the waiting_human event.
     *
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

        // Check details first (interrupt payloads nest kind=interrupt under details)
        if ('interrupt' === ($details['kind'] ?? null)) {
            $interrupt = $details;
        }

        // Fallback: check result.kind (older interrupt path)
        if (null === $interrupt && 'interrupt' === ($result->result['kind'] ?? null)) {
            $interrupt = $result->result;
        }

        if (null === $interrupt) {
            return null;
        }

        // Generic passthrough: start with all keys from the interrupt array.
        // AgentCore does not enumerate the carried fields — whatever the tool
        // produced (structural markers, UI metadata, future fields) survives.
        $payload = $interrupt;

        // ── Core typed fallbacks (applied ON TOP of passthrough values) ──

        // tool_call_id always comes from the message, not the interrupt array
        $payload['tool_call_id'] = $result->toolCallId;

        // tool_name from outer result (authoritative execution record)
        if (\is_string($result->result['tool_name'] ?? null)) {
            $payload['tool_name'] = $result->result['tool_name'];
        }

        // question_id: prefer interrupt value, fall back to toolCallId
        $payload['question_id'] = \is_string($payload['question_id'] ?? null)
            ? $payload['question_id']
            : $result->toolCallId;

        // prompt: prefer interrupt value, fall back to generic default
        $payload['prompt'] = \is_string($payload['prompt'] ?? null)
            ? $payload['prompt']
            : 'Human input required.';

        // schema: prefer interrupt value, fall back to string type
        $payload['schema'] = \is_array($payload['schema'] ?? null)
            ? $payload['schema']
            : ['type' => 'string'];

        // No blanket array_filter — preserve all interrupt values as-is.
        // A blanket null-strip would break payloads with explicit null defaults
        // (e.g. default => null) that must survive to the waiting_human event.
        return $payload;
    }
}
