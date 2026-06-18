<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

/**
 * Thrown when AgentMessage history fails the tool-call/tool-result
 * sequence invariant before a provider call.
 *
 * Carries sanitized diagnostic metadata only — no raw prompt text,
 * tool output, tool arguments, environment values, or API keys.
 */
final class MalformedToolCallSequenceException extends \RuntimeException
{
    /**
     * @param list<string> $expectedIds
     */
    private function __construct(
        string $message,
        public readonly string $violationType,
        public readonly int $messageIndex,
        public readonly string $role,
        public readonly array $expectedIds,
        public readonly ?string $toolCallId = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<string> $expectedIds
     */
    public static function unclosedBatch(int $index, string $role, int $openCount, array $expectedIds): self
    {
        return new self(
            message: \sprintf(
                'Tool-call sequence violation at message %d (role: %s): %d pending tool result(s) from previous assistant were never satisfied. Expected tool_call_ids: [%s].',
                $index,
                $role,
                $openCount,
                implode(', ', array_map(static fn (string $id): string => \sprintf('"%s"', $id), $expectedIds)),
            ),
            violationType: 'unclosed_batch',
            messageIndex: $index,
            role: $role,
            expectedIds: $expectedIds,
        );
    }

    /**
     * @param list<string> $expectedIds
     */
    public static function missingToolResults(int $index, int $missingCount, array $expectedIds): self
    {
        return new self(
            message: \sprintf(
                'Tool-call sequence violation at end of messages: %d tool result(s) missing for pending tool_call_ids: [%s].',
                $missingCount,
                implode(', ', array_map(static fn (string $id): string => \sprintf('"%s"', $id), $expectedIds)),
            ),
            violationType: 'missing_tool_results',
            messageIndex: $index,
            role: 'end',
            expectedIds: $expectedIds,
        );
    }

    /**
     * @param list<string> $expectedIds
     */
    public static function orphanToolMessage(int $index, ?string $toolCallId, array $expectedIds): self
    {
        $idStr = null !== $toolCallId ? \sprintf('"%s"', $toolCallId) : 'null';

        return new self(
            message: \sprintf(
                'Tool-call sequence violation at message %d: orphan tool message with tool_call_id=%s (no open batch expecting it).',
                $index,
                $idStr,
            ),
            violationType: 'orphan_tool_message',
            messageIndex: $index,
            role: 'tool',
            expectedIds: $expectedIds,
            toolCallId: $toolCallId,
        );
    }

    /**
     * @param list<string> $expectedIds
     */
    public static function unknownToolCallId(int $index, string $toolCallId, array $expectedIds): self
    {
        return new self(
            message: \sprintf(
                'Tool-call sequence violation at message %d: unknown tool_call_id "%s" — not in the expected batch [%s].',
                $index,
                $toolCallId,
                implode(', ', array_map(static fn (string $id): string => \sprintf('"%s"', $id), $expectedIds)),
            ),
            violationType: 'unknown_tool_call_id',
            messageIndex: $index,
            role: 'tool',
            expectedIds: $expectedIds,
            toolCallId: $toolCallId,
        );
    }

    /**
     * @param list<string> $expectedIds
     */
    public static function duplicateToolResult(int $index, string $toolCallId, array $expectedIds): self
    {
        return new self(
            message: \sprintf(
                'Tool-call sequence violation at message %d: duplicate tool result for tool_call_id "%s".',
                $index,
                $toolCallId,
            ),
            violationType: 'duplicate_tool_result',
            messageIndex: $index,
            role: 'tool',
            expectedIds: $expectedIds,
            toolCallId: $toolCallId,
        );
    }
}
