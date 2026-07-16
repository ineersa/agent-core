<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\MalformedToolCallSequenceException;

/**
 * Cut-point selection and tool-call sequence safety validation for
 * compaction boundary decisions.
 *
 * Responsibilities:
 *   - Walk backward from newest to oldest, accumulating token estimates
 *     until keep_recent_tokens is reached (find initial boundary).
 *   - Walk backward from the tentative boundary to find a safe cut point,
 *     preferring user-message boundaries.
 *   - Validate that each candidate boundary satisfies both cross-boundary
 *     invariants (no split tool-call/tool-result groups) and partition
 *     validity (each partition is independently provider-valid).
 *
 * Safe cut invariants:
 *   - Assistant/tool-call groups are indivisible across partitions.
 *   - Never retain a tool result whose assistant tool call was summarized
 *     away (produces an orphan in the retained tail).
 *   - Never summarize an assistant tool-call message while retaining its
 *     tool results (produces unclosed expected call IDs in history).
 *   - Both the summarize prefix and retained tail must be independently
 *     valid provider-submittable tool-call sequences.
 *
 * Tool messages with a null/empty toolCallId are deferred to partition
 * validity — the provider-level validator treats call-ID-less tool
 * messages as harmless unless there is an open tool-call batch expecting
 * specific IDs.
 */
final class CompactionBoundarySelector
{
    public function __construct(
        private readonly CompactionTokenEstimator $tokenEstimator,
        private readonly AgentMessageToolCallSequenceValidator $sequenceValidator,
    ) {
    }

    /**
     * Walk backward from newest to oldest, accumulating token estimates
     * until at least targetTokens is accumulated. Returns the index
     * of the first message in the tail (i.e. the cut boundary).
     *
     * Returns null if all messages fit inside the target.
     *
     * @param list<AgentMessage> $messages
     *
     * @return int|null Boundary index (first message of the retained tail)
     */
    public function findBoundary(array $messages, int $targetTokens): ?int
    {
        $count = \count($messages);
        $accumulated = 0;

        for ($i = $count - 1; $i >= 0; --$i) {
            $accumulated += $this->tokenEstimator->estimateMessageTokens($messages[$i]);

            if ($accumulated >= $targetTokens) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Find a safe boundary at or before the given tentative boundary.
     *
     * Walks backward from tentativeBoundary, preferring cuts before user
     * messages within a bounded window. Once the window is exhausted,
     * returns the best nearby safe boundary (closest to the target) rather
     * than walking all the way back to the oldest user message.
     *
     * The user-preference window prevents the pathological case where a
     * single-user-turn tool-heavy session collapses all the way to the
     * oldest user message when many safe assistant/tool-group boundaries
     * exist closer to the target cut point.
     *
     * @param list<AgentMessage> $messages
     * @param int                $tentativeBoundary Index of the first retained message
     *
     * @return int|null A safe boundary index, or null if none found
     */
    public function findSafeBoundary(array $messages, int $tentativeBoundary): ?int
    {
        if ($tentativeBoundary < 1) {
            return null;
        }

        // Maximum distance from the tentative boundary within which we
        // will prefer a user-role boundary over a safe assistant/tool-group
        // boundary.  Beyond this window the best safe boundary (closest
        // to the target) is accepted regardless of role.
        $userPreferenceWindow = 20;

        $fallback = null;

        for ($candidate = $tentativeBoundary; $candidate >= 1; --$candidate) {
            $distanceFromTarget = $tentativeBoundary - $candidate;

            // Past the user-preference window and we have a viable
            // fallback — stop walking.  Accepting the nearest safe
            // boundary prevents useless collapse to the oldest user
            // message when many safe assistant/tool-group boundaries
            // exist near the target.
            if ($distanceFromTarget > $userPreferenceWindow && null !== $fallback) {
                return $fallback;
            }

            if (!$this->isSafeCutPoint($messages, $candidate)) {
                continue;
            }

            // Track the best (closest to target) safe boundary.
            if (null === $fallback) {
                $fallback = $candidate;
            }

            // Prefer user boundaries only within the preference window.
            if ('user' === $messages[$candidate]->role && $distanceFromTarget <= $userPreferenceWindow) {
                return $candidate;
            }
        }

        return $fallback;
    }

    /**
     * Find the smallest safe retained body tail for forced virtual compaction.
     *
     * Walks from the newest body message backward, preferring the largest
     * boundary index (smallest retained tail) that keeps assistant/tool-call
     * groups intact on both sides of the partition.
     *
     * Returns 0 when the entire compactable body must be summarized together
     * and only the prologue is retained.
     *
     * @param list<AgentMessage> $body Messages after prologue extraction
     *
     * @return int|null Safe boundary index, or null when no partition is valid
     */
    public function findForcedSafeBoundary(array $body): ?int
    {
        $count = \count($body);
        if ($count < 2) {
            return null;
        }

        for ($boundary = $count - 1; $boundary >= 1; --$boundary) {
            if (!$this->isSafeCutPoint($body, $boundary)) {
                continue;
            }

            if ([] !== \array_slice($body, 0, $boundary)) {
                return $boundary;
            }
        }

        if ($this->isSafeCutPoint($body, 0)) {
            return 0;
        }

        return null;
    }

    /**
     * Check whether cutting at the given boundary is safe.
     *
     * Two-layer safety: cross-boundary invariants + partition validity.
     *
     * Cross-boundary invariants prevent splitting tool-call/tool-result
     * groups across the summarize/retain partition:
     *   - The assistant that declares tool_calls and its tool results
     *     are an indivisible group. Summarizing one side while retaining
     *     the other produces a malformed conversation the LLM cannot
     *     interpret.
     *   - A tool result whose assistant tool-call was summarized away
     *     would become an orphan in the retained tail — no preceding
     *     assistant message carries the matching tool_call_id.
     *   - Conversely, summarizing the assistant tool-call message while
     *     retaining its tool results would leave unclosed expected call
     *     IDs in the history.
     *
     * Tool messages with a null/empty toolCallId are deferred to
     * partition validity (isValidSequence). The Provider-level
     * validator treats call-ID-less tool messages as harmless unless
     * there is an open tool-call batch expecting specific IDs; the
     * cross-boundary layer therefore skips them rather than making
     * assumptions about orphan relationships.
     *
     * Partition validity checks that each standalone partition
     * (summarize prefix and retained tail) is independently a
     * well-formed tool-call sequence. Even if the cross-boundary
     * invariants pass, one side could contain an unclosed batch or
     * other sequence violation that would break the summarization
     * LLM call or the resumed conversation.
     *
     * @param list<AgentMessage> $messages
     * @param int                $boundary Index of first retained message
     */
    private function isSafeCutPoint(array $messages, int $boundary): bool
    {
        $count = \count($messages);

        // Collect tool_call_ids declared by assistant messages in each
        // partition. These sets drive the cross-boundary orphan checks.

        $summarizeToolCallIds = [];
        for ($i = 0; $i < $boundary; ++$i) {
            $extracted = AgentMessageToolCallSequenceValidator::extractToolCallIds($messages[$i]);
            foreach ($extracted as $id) {
                $summarizeToolCallIds[$id] = true;
            }
        }

        $retainedAssistantToolCallIds = [];
        for ($i = $boundary; $i < $count; ++$i) {
            $extracted = AgentMessageToolCallSequenceValidator::extractToolCallIds($messages[$i]);
            foreach ($extracted as $id) {
                $retainedAssistantToolCallIds[$id] = true;
            }
        }

        // Check retained tool results against both tool_call_id sets.
        // A retained tool result must NOT belong to an assistant that
        // was summarized away (orphan), and must belong to an assistant
        // in the retained partition (otherwise it has no matching call).
        for ($i = $boundary; $i < $count; ++$i) {
            if ('tool' !== $messages[$i]->role) {
                continue;
            }

            $toolCallId = $messages[$i]->toolCallId;

            // Call-ID-less tool messages are deferred to the
            // partition validity layer (isValidSequence) which
            // handles them per Provider rules.
            if (null === $toolCallId || '' === $toolCallId) {
                continue;
            }

            // Retained tool result whose assistant was summarized
            // away — orphan in the retained partition.
            if (isset($summarizeToolCallIds[$toolCallId])) {
                return false;
            }

            // Retained tool result with no matching assistant in
            // either partition — unknown call ID.
            if (!isset($retainedAssistantToolCallIds[$toolCallId])) {
                return false;
            }
        }

        // ── Partition validity ────────────────────────────────────
        //
        // Each partition must be independently valid as a
        // provider-submittable tool-call sequence. Even if the
        // cross-boundary invariants pass, one side could contain an
        // unclosed batch (e.g. assistant with tool_calls not followed
        // by matching tool results) or a malformed sequence that would
        // break the summarization LLM call or the resumed conversation.

        if (!$this->isValidSequence(\array_slice($messages, 0, $boundary))) {
            return false;
        }

        if (!$this->isValidSequence(\array_slice($messages, $boundary))) {
            return false;
        }

        return true;
    }

    /**
     * Check whether a message sequence is provider-valid per the
     * tool-call/tool-result sequence invariant.
     *
     * @param list<AgentMessage> $messages
     */
    private function isValidSequence(array $messages): bool
    {
        try {
            $this->sequenceValidator->validate($messages);
        } catch (MalformedToolCallSequenceException) {
            return false;
        }

        return true;
    }
}
