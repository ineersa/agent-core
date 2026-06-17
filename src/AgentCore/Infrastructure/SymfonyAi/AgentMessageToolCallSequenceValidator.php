<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Validates the tool-call/tool-result sequence invariant over a list of
 * AgentMessages before they are submitted to an LLM provider.
 *
 * A well-formed tool-call sequence follows OpenAI-style ordering:
 *   1. An assistant message with tool_calls opens an expected batch.
 *   2. The following messages must be tool results until every expected
 *      tool_call_id is satisfied.
 *   3. Any violation (missing tool results, orphan tool messages, duplicate
 *      tool results, unknown tool_call_ids) fails with a sanitized diagnostic.
 *
 * This validator is used by LlmPlatformAdapter before any provider call.
 * On violation it throws MalformedToolCallSequenceException, preventing the
 * provider from receiving malformed conversation history.
 *
 * A silent filter is NOT acceptable — the invariant must fail loudly.
 */
final class AgentMessageToolCallSequenceValidator
{
    /**
     * Validate message sequence. Throws on first violation.
     *
     * @param list<AgentMessage> $messages
     *
     * @throws MalformedToolCallSequenceException on any sequence violation
     */
    public function validate(array $messages): void
    {
        /** @var list<string> $openToolCallIds */
        $openToolCallIds = [];

        foreach ($messages as $index => $message) {
            if ('tool' === $message->role) {
                $this->validateToolMessage($index, $message, $openToolCallIds);

                continue;
            }

            if ('assistant' === $message->role) {
                $toolCalls = $this->extractToolCallIds($message);

                if ([] !== $toolCalls) {
                    // An assistant message with tool calls opens a new
                    // expected batch.  If there is an open batch from
                    // a previous assistant message, that is a violation
                    // (previous batch was never closed with tool results).
                    if ([] !== $openToolCallIds) {
                        throw MalformedToolCallSequenceException::unclosedBatch($index, $message->role, \count($openToolCallIds), $openToolCallIds);
                    }

                    /** @var list<string> $openToolCallIds */
                    $openToolCallIds = $toolCalls;
                }

                continue;
            }

            // A non-assistant, non-tool message (user, system, etc.)
            // while there is an open tool-call batch is a violation:
            // the previous assistant's tool_calls were never answered
            // by tool results before the next user/system message.
            if ([] !== $openToolCallIds) {
                throw MalformedToolCallSequenceException::unclosedBatch($index, $message->role, \count($openToolCallIds), $openToolCallIds);
            }
        }

        // End of messages with an unclosed tool-call batch.
        if ([] !== $openToolCallIds) {
            throw MalformedToolCallSequenceException::missingToolResults(\count($messages), \count($openToolCallIds), $openToolCallIds);
        }
    }

    /**
     * Extract valid tool_call_ids from an assistant message's metadata.
     *
     * Returns only IDs that are non-empty strings. Malformed entries
     * (non-string id) are silently skipped, matching AgentMessageConverter's
     * behavior.
     *
     * @return list<string>
     */
    private function extractToolCallIds(AgentMessage $message): array
    {
        $rawToolCalls = $message->metadata['tool_calls'] ?? null;

        if (!\is_array($rawToolCalls)) {
            return [];
        }

        $ids = [];

        foreach ($rawToolCalls as $rawToolCall) {
            if (!\is_array($rawToolCall)) {
                continue;
            }

            $id = $rawToolCall['id'] ?? null;

            if (\is_string($id) && '' !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Validate a tool message against the current open batch.
     *
     * Validate a tool message against the current open batch.
     *
     * @param int                $index            Message index in the sequence
     * @param AgentMessage       $message          The tool result message
     * @param array<int, string> &$openToolCallIds Mutable list of expected tool_call_ids (modified in place)
     *
     * @throws MalformedToolCallSequenceException
     */
    private function validateToolMessage(int $index, AgentMessage $message, array &$openToolCallIds): void
    {
        $toolCallId = $message->toolCallId;

        // Tool message without a tool_call_id is unusual but not a
        // sequence violation by itself — it may be a free-form tool
        // message.  Only enforce if there is an open batch expecting
        // specific IDs.
        if (null === $toolCallId || '' === $toolCallId) {
            if ([] !== $openToolCallIds) {
                // An open batch expects tool results, but this tool
                // message has no call ID — treat as orphan.
                throw MalformedToolCallSequenceException::orphanToolMessage($index, $toolCallId, $openToolCallIds);
            }

            return;
        }

        if ([] === $openToolCallIds) {
            // Tool message with a call ID, but no open batch expects it.
            throw MalformedToolCallSequenceException::orphanToolMessage($index, $toolCallId, $openToolCallIds);
        }

        $foundIndex = array_search($toolCallId, $openToolCallIds, true);

        if (false === $foundIndex) {
            // Unknown tool_call_id — not in the expected batch.
            // This also covers the duplicate case: if the ID was
            // already satisfied and removed from the batch, a
            // second tool result for the same ID is rejected here
            // as unknown.
            throw MalformedToolCallSequenceException::unknownToolCallId($index, $toolCallId, $openToolCallIds);
        }

        // Remove the expected ID from the open batch — it is now
        // satisfied.  If the same ID appears again (duplicate), the
        // array_search will return false because it was already removed.
        unset($openToolCallIds[$foundIndex]);
        $openToolCallIds = array_values($openToolCallIds); // Re-index
    }
}
