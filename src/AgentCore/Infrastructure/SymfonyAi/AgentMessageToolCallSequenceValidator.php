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
    /** @var list<string> open batch of tool_call_ids expected to be satisfied */
    private array $openToolCallIds = [];

    /** @var array<string, true> tool_call_ids already satisfied in the current/recent batch */
    private array $satisfiedToolCallIds = [];

    /**
     * Validate message sequence. Throws on first violation.
     *
     * @param list<AgentMessage> $messages
     *
     * @throws MalformedToolCallSequenceException on any sequence violation
     */
    public function validate(array $messages): void
    {
        $this->openToolCallIds = [];
        $this->satisfiedToolCallIds = [];

        foreach ($messages as $index => $message) {
            if ('tool' === $message->role) {
                $this->validateToolMessage($index, $message);

                continue;
            }

            if ('assistant' === $message->role) {
                $toolCalls = self::extractToolCallIds($message);

                if ([] !== $toolCalls) {
                    // An assistant message with tool calls opens a new
                    // expected batch.  If there is an open batch from
                    // a previous assistant message, that is a violation
                    // (previous batch was never closed with tool results).
                    if ([] !== $this->openToolCallIds) {
                        throw MalformedToolCallSequenceException::unclosedBatch($index, $message->role, \count($this->openToolCallIds), $this->openToolCallIds);
                    }

                    // Reset satisfied tracking for the new batch.
                    $this->satisfiedToolCallIds = [];

                    /* @var list<string> $this->openToolCallIds */
                    $this->openToolCallIds = $toolCalls;
                }

                continue;
            }

            // A non-assistant, non-tool message (user, system, etc.)
            // while there is an open tool-call batch is a violation:
            // the previous assistant's tool_calls were never answered
            // by tool results before the next user/system message.
            if ([] !== $this->openToolCallIds) {
                throw MalformedToolCallSequenceException::unclosedBatch($index, $message->role, \count($this->openToolCallIds), $this->openToolCallIds);
            }

            // Batch fully consumed, reset satisfied tracking so orphan
            // tool messages after this point are reported as orphan
            // rather than duplicate.
            $this->satisfiedToolCallIds = [];
        }

        // End of messages with an unclosed tool-call batch.
        if ([] !== $this->openToolCallIds) {
            throw MalformedToolCallSequenceException::missingToolResults(\count($messages), \count($this->openToolCallIds), $this->openToolCallIds);
        }
    }

    /**
     * Extract valid tool_call_ids from an assistant message's metadata.
     *
     * Returns only IDs that are non-empty strings. Malformed entries
     * (non-string id) are silently skipped, matching AgentMessageConverter's
     * behavior.
     *
     * Shared implementation used by this validator and by SessionCompactor
     * for cut-point safety checks.
     *
     * @return list<string>
     */
    public static function extractToolCallIds(AgentMessage $message): array
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
     * @param int          $index   Message index in the sequence
     * @param AgentMessage $message The tool result message
     *
     * @throws MalformedToolCallSequenceException
     */
    private function validateToolMessage(int $index, AgentMessage $message): void
    {
        $toolCallId = $message->toolCallId;

        // Tool message without a tool_call_id is unusual but not a
        // sequence violation by itself — it may be a free-form tool
        // message.  Only enforce if there is an open batch expecting
        // specific IDs.
        if (null === $toolCallId || '' === $toolCallId) {
            if ([] !== $this->openToolCallIds) {
                // An open batch expects tool results, but this tool
                // message has no call ID — treat as orphan.
                throw MalformedToolCallSequenceException::orphanToolMessage($index, $toolCallId, $this->openToolCallIds);
            }

            return;
        }

        // Check for duplicate before orphan/unknown — a tool_call_id
        // that was already satisfied in the current batch is a
        // duplicate even if the open batch is now empty (all expected
        // results received).
        if (isset($this->satisfiedToolCallIds[$toolCallId])) {
            throw MalformedToolCallSequenceException::duplicateToolResult($index, $toolCallId, $this->openToolCallIds);
        }

        if ([] === $this->openToolCallIds) {
            // Tool message with a call ID, but no open batch expects it.
            throw MalformedToolCallSequenceException::orphanToolMessage($index, $toolCallId, $this->openToolCallIds);
        }

        $foundIndex = array_search($toolCallId, $this->openToolCallIds, true);

        if (false === $foundIndex) {
            // Unknown tool_call_id — not in the expected batch
            // and not previously satisfied.
            throw MalformedToolCallSequenceException::unknownToolCallId($index, $toolCallId, $this->openToolCallIds);
        }

        // Remove the expected ID from the open batch and mark as satisfied.
        unset($this->openToolCallIds[$foundIndex]);
        $this->openToolCallIds = array_values($this->openToolCallIds);
        $this->satisfiedToolCallIds[$toolCallId] = true;
    }
}
