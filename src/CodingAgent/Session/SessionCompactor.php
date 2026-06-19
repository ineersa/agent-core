<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\MalformedToolCallSequenceException;
use Ineersa\CodingAgent\Config\CompactionConfig;

/**
 * Compaction preparation, safe cut-point selection, summarization prompt
 * construction, and compacted message assembly.
 *
 * This service contains the pure algorithm and prompt-construction logic.
 * It does NOT call any LLM or platform — COMP-02 handles model invocation.
 *
 * Preparation algorithm (plan section 9):
 *   1. Hydrate all messages into AgentMessage objects.
 *   2. Estimate total tokens.
 *   3. Walk backward from newest to oldest, accumulating tokens until
 *      keepRecentTokens is reached.
 *   4. Move the boundary to a safe cut point (plan section 10).
 *   5. Return partitions or null if no compaction is needed/possible.
 *
 * Safe cut rules (plan section 10):
 *   - Prefer cutting before a user message.
 *   - Never retain a tool result whose assistant tool call was summarized away.
 *   - Never summarize an assistant tool-call message while retaining its tool results.
 *   - Assistant/tool-call groups are indivisible.
 *   - If no safe boundary exists, skip compaction.
 */
final class SessionCompactor
{
    // ── Prompt texts (plan section 5) ────────────────────────────────

    private const string SUMMARIZATION_SYSTEM_MESSAGE = "You are a context summarization assistant. Read the conversation and produce only a handoff summary.\n\nDo not continue the conversation. Do not answer questions from the conversation. Do not call tools. Output only the summary text.";

    private const string SUMMARIZATION_USER_PROMPT = "You are performing a CONTEXT CHECKPOINT COMPACTION. Create a handoff summary for another LLM that will resume the task.\n\nInclude:\n- Current progress and key decisions made\n- Important context, constraints, or user preferences\n- What remains to be done (clear next steps)\n- Any critical data, examples, file paths, commands, errors, or references needed to continue\n\nIf a prior compaction summary exists in the conversation, incorporate it and preserve still-relevant facts.\n\nBe concise, structured, and focused on helping the next LLM seamlessly continue the work.";

    private const string CUSTOM_INSTRUCTIONS_PREFIX = "Additional user instructions for this compaction:\n";

    private const string SUMMARY_PREFIX = "The conversation history before this point was compacted into the following handoff summary. Use it as prior context, not as a new user request.\n\n<summary>\n";

    private const string SUMMARY_SUFFIX = "\n</summary>";
    private readonly AgentMessageToolCallSequenceValidator $sequenceValidator;

    public function __construct(
        ?AgentMessageToolCallSequenceValidator $sequenceValidator = null,
    ) {
        $this->sequenceValidator = $sequenceValidator ?? new AgentMessageToolCallSequenceValidator();
    }

    // ── Preparation ───────────────────────────────────────────────────

    /**
     * Prepare compaction partitions for a message list.
     *
     * @param list<AgentMessage> $messages Current message list
     * @param CompactionConfig   $settings Compaction settings (budgets, enabled flag)
     *
     * @return CompactionPreparationDTO|null Null when no compaction is needed
     *                                       (short session, no safe boundary, or compaction disabled)
     */
    public function prepare(array $messages, CompactionConfig $settings): ?CompactionPreparationDTO
    {
        if (!$settings->enabled) {
            return null;
        }

        $count = \count($messages);

        // Nothing to compact with 0 or 1 messages.
        if ($count < 2) {
            return null;
        }

        $totalEstimate = $this->estimateTokens($messages);

        // No need to compact if the entire session fits within keepRecentTokens.
        if ($totalEstimate <= $settings->keepRecentTokens) {
            return null;
        }

        // Walk backward accumulating tokens until we retain at least keepRecentTokens.
        $boundary = $this->findBoundary($messages, $settings->keepRecentTokens);

        if (null === $boundary) {
            return null;
        }

        // Move the boundary to a safe cut point.
        $safeBoundary = $this->findSafeBoundary($messages, $boundary);

        if (null === $safeBoundary) {
            return null;
        }

        $messagesToSummarize = \array_slice($messages, 0, $safeBoundary);
        $retainedTail = \array_slice($messages, $safeBoundary);

        $priorSummaryPresent = $this->detectPriorCompactSummary($messagesToSummarize);

        return new CompactionPreparationDTO(
            messagesToSummarize: $messagesToSummarize,
            retainedTailMessages: $retainedTail,
            tokenEstimateBefore: $totalEstimate,
            messagesCompacted: \count($messagesToSummarize),
            messagesRetained: \count($retainedTail),
            firstRetainedIndex: $safeBoundary,
            priorSummaryPresent: $priorSummaryPresent,
        );
    }

    // ── Prompt construction ───────────────────────────────────────────

    /**
     * Build the message list for the summarization LLM call.
     *
     * Returns [system message, ...messagesToSummarize, user prompt message]
     * as AgentMessage instances. The system and user prompts are synthetic
     * messages with 'system' and 'user' roles respectively.
     *
     * @param CompactionPreparationDTO $preparation        Prepared partitions
     * @param string|null              $customInstructions Optional user-provided instructions
     *
     * @return list<AgentMessage>
     */
    public function buildSummarizationMessages(
        CompactionPreparationDTO $preparation,
        ?string $customInstructions,
    ): array {
        $systemMessage = new AgentMessage(
            role: 'system',
            content: [['type' => 'text', 'text' => self::SUMMARIZATION_SYSTEM_MESSAGE]],
        );

        $userPromptText = self::SUMMARIZATION_USER_PROMPT;

        if (null !== $customInstructions && '' !== trim($customInstructions)) {
            $userPromptText .= "\n\n".self::CUSTOM_INSTRUCTIONS_PREFIX.trim($customInstructions);
        }

        $userPromptMessage = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $userPromptText]],
        );

        return [
            $systemMessage,
            ...$preparation->messagesToSummarize,
            $userPromptMessage,
        ];
    }

    // ── Compacted message construction ────────────────────────────────

    /**
     * Build the compacted message list from a summarization result.
     *
     * Returns a CompactResultDTO containing:
     *   - The full compacted message list: [summaryMessage, ...retainedTail]
     *   - The injected summary message with compact_summary metadata
     *   - Before/after token estimates
     *
     * @param string                   $summaryText Raw summary text from the model
     * @param CompactionPreparationDTO $preparation Preparation result
     */
    public function buildCompactedMessages(
        string $summaryText,
        CompactionPreparationDTO $preparation,
    ): CompactResultDTO {
        $summaryPrefix = self::SUMMARY_PREFIX.$summaryText.self::SUMMARY_SUFFIX;

        $summaryMessage = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $summaryPrefix]],
            metadata: ['compact_summary' => true],
        );

        $compactedMessages = [
            $summaryMessage,
            ...$preparation->retainedTailMessages,
        ];

        $tokenEstimateAfter = $this->estimateTokens($compactedMessages);

        return new CompactResultDTO(
            summaryText: $summaryText,
            summaryMessage: $summaryMessage,
            compactedMessages: $compactedMessages,
            tokenEstimateBefore: $preparation->tokenEstimateBefore,
            tokenEstimateAfter: $tokenEstimateAfter,
            messagesCompacted: $preparation->messagesCompacted,
            messagesRetained: $preparation->messagesRetained,
            firstRetainedIndex: $preparation->firstRetainedIndex,
        );
    }

    // ── Token estimation ──────────────────────────────────────────────

    /**
     * Estimate token count for a list of AgentMessages.
     *
     * Uses the same JSON-length/4 approximation as
     * ReplayService::estimateTokens().  Public so the future
     * auto-compaction trigger policy (COMP-06) can check whether
     * the estimated context exceeds the reserve threshold before
     * deciding to compact.
     *
     * @param list<AgentMessage> $messages
     */
    public function estimateTokens(array $messages): int
    {
        $serialized = json_encode(
            array_map(static fn (AgentMessage $m): array => $m->toArray(), $messages),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );

        if (false === $serialized) {
            return 0;
        }

        return (int) ceil(\strlen($serialized) / 4);
    }

    // ── Prior summary detection ───────────────────────────────────────

    /**
     * Detect whether any message in the list already has compact_summary metadata.
     *
     * @param list<AgentMessage> $messages
     */
    private function detectPriorCompactSummary(array $messages): bool
    {
        foreach ($messages as $message) {
            $isCompact = $message->metadata['compact_summary'] ?? null;

            if (true === $isCompact) {
                return true;
            }
        }

        return false;
    }

    // ── Cut-point selection ───────────────────────────────────────────

    /**
     * Walk backward from newest to oldest, accumulating token estimates
     * until at least targetTokens is accumulated. Returns the index
     * of the first message in the tail (i.e. the cut boundary).
     *
     * Returns null if all messages fit inside the target (shouldn't happen
     * since we already checked totalEstimate > keepRecentTokens).
     *
     * @param list<AgentMessage> $messages
     *
     * @return int|null Boundary index (first message of the retained tail)
     */
    private function findBoundary(array $messages, int $targetTokens): ?int
    {
        $count = \count($messages);
        $accumulated = 0;

        for ($i = $count - 1; $i >= 0; --$i) {
            $accumulated += $this->estimateMessageTokens($messages[$i]);

            if ($accumulated >= $targetTokens) {
                return $i;
            }
        }

        // All messages fit — shouldn't be called if totalEstimate > target.
        return null;
    }

    /**
     * Find a safe boundary at or before the given tentative boundary.
     *
     * Rules:
     *   1. Prefer cutting before a user message.
     *   2. Never split an assistant tool-call group from its tool results.
     *   3. Never leave an orphan tool result in the retained tail.
     *   4. Conservative: keep more messages rather than fewer.
     *
     * Walks backward from tentativeBoundary, preferring cuts before user
     * messages. Falls back to any safe cut point if no user boundary exists
     * within range.
     *
     * @param list<AgentMessage> $messages
     * @param int                $tentativeBoundary Index of the first retained message (from findBoundary)
     *
     * @return int|null A safe boundary index, or null if none found
     */
    private function findSafeBoundary(array $messages, int $tentativeBoundary): ?int
    {
        $count = \count($messages);

        // If boundary is at or before the first message, nothing to summarize.
        if ($tentativeBoundary < 1) {
            return null;
        }

        $fallback = null;

        // Walk backward from the tentative boundary, preferring
        // safe cuts before user messages.
        for ($candidate = $tentativeBoundary; $candidate >= 1; --$candidate) {
            if (!$this->isSafeCutPoint($messages, $candidate)) {
                continue;
            }

            // User-boundary preference: if the first retained message
            // is a user message, return immediately.
            if ('user' === $messages[$candidate]->role) {
                return $candidate;
            }

            // Record the first safe boundary found (largest index)
            // as fallback in case no user boundary exists.
            if (null === $fallback) {
                $fallback = $candidate;
            }
        }

        return $fallback;
    }

    /**
     * Check whether cutting at the given boundary is safe.
     *
     * The boundary is the index of the first retained message.
     * Messages[0..boundary-1] will be summarized.
     * Messages[boundary..end] will be retained.
     *
     * Safety checks (two layers):
     *
     * Cross-boundary invariants:
     *   - No tool result in the retained tail whose assistant tool call is in the summarize partition.
     *   - No assistant tool call in the summarize partition whose tool results are in the retained tail.
     *   - No tool result in the retained tail without a corresponding assistant tool call (orphan).
     *
     * Partition validity (each side is provider-valid per AgentMessageToolCallSequenceValidator):
     *   - The summarize prefix must be a valid message sequence (no unclosed batches, no orphans).
     *   - The retained tail must be a valid message sequence.
     *
     * @param list<AgentMessage> $messages
     * @param int                $boundary Index of first retained message
     */
    private function isSafeCutPoint(array $messages, int $boundary): bool
    {
        $count = \count($messages);

        // ── Cross-boundary invariants ─────────────────────────────

        // Collect all tool_call_ids opened by assistant messages in the summarize partition.
        $summarizeToolCallIds = [];
        for ($i = 0; $i < $boundary; ++$i) {
            $extracted = $this->extractToolCallIds($messages[$i]);
            foreach ($extracted as $id) {
                $summarizeToolCallIds[$id] = true;
            }
        }

        // Collect all tool_call_ids expected from assistant messages in the retained tail.
        $retainedAssistantToolCallIds = [];
        for ($i = $boundary; $i < $count; ++$i) {
            $extracted = $this->extractToolCallIds($messages[$i]);
            foreach ($extracted as $id) {
                $retainedAssistantToolCallIds[$id] = true;
            }
        }

        // Check every retained tool message.
        for ($i = $boundary; $i < $count; ++$i) {
            if ('tool' !== $messages[$i]->role) {
                continue;
            }

            $toolCallId = $messages[$i]->toolCallId;

            if (null === $toolCallId || '' === $toolCallId) {
                // Tool message without a call ID — not a cross-boundary
                // concern.  Partition validity (isValidSequence) handles
                // whether it's safe within its partition.
                continue;
            }

            // Rule: never retain a tool result if its assistant tool call was summarized away.
            if (isset($summarizeToolCallIds[$toolCallId])) {
                return false;
            }

            // Rule: no orphan retained tool result.
            // A tool result is orphan if it's not opened by a retained assistant message.
            // Since the assistant is not in the summarize partition (checked above),
            // and not in the retained tail, it's genuinely orphan.
            if (!isset($retainedAssistantToolCallIds[$toolCallId])) {
                return false;
            }
        }

        // Rule: never summarize an assistant tool-call message while retaining its tool results.
        foreach ($summarizeToolCallIds as $toolCallId => $_unused) {
            for ($i = $boundary; $i < $count; ++$i) {
                if ('tool' === $messages[$i]->role && $messages[$i]->toolCallId === $toolCallId) {
                    return false;
                }
            }
        }

        // ── Partition validity ────────────────────────────────────
        // Each partition must be provider-valid on its own so that the
        // summarization call and the resumed conversation both satisfy
        // the tool-call sequence invariant.

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
     * Reuses AgentMessageToolCallSequenceValidator, which is also used by
     * LlmPlatformAdapter before every provider call.  On violation the
     * validator throws MalformedToolCallSequenceException; we catch it and
     * return false.  This is intentional local degradation: an unsafe
     * boundary causes prepare() to return null (skip compaction) rather
     * than risking an invalid conversation history.
     *
     * Logging is intentionally deferred to the caller (COMP-02 pipeline):
     * this service is a pure algorithm; the pipeline layer that calls
     * prepare() owns the observable failure/log/event behaviour for
     * null/no-op (compaction skipped, compaction failed).
     *
     * @param list<AgentMessage> $messages
     */
    private function isValidSequence(array $messages): bool
    {
        try {
            $this->sequenceValidator->validate($messages);
        } catch (MalformedToolCallSequenceException) {
            // Intentional local degradation: an invalid partition
            // means the candidate cut point is unsafe; skip
            // compaction rather than risk provider errors.
            // Logging is handled by the pipeline caller (COMP-02).

            return false;
        }

        return true;
    }

    /**
     * Extract tool_call_ids from an assistant message's metadata.
     *
     * Mirrors AgentMessageToolCallSequenceValidator::extractToolCallIds() logic.
     *
     * @return list<string>
     */
    private function extractToolCallIds(AgentMessage $message): array
    {
        if ('assistant' !== $message->role) {
            return [];
        }

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
     * Estimate tokens for a single AgentMessage using the same approximation.
     */
    private function estimateMessageTokens(AgentMessage $message): int
    {
        $serialized = json_encode(
            $message->toArray(),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );

        if (false === $serialized) {
            return 0;
        }

        return (int) ceil(\strlen($serialized) / 4);
    }
}
