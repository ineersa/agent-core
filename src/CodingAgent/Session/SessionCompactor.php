<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\MalformedToolCallSequenceException;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Symfony\Component\String\UnicodeString;

/**
 * Compaction preparation, safe cut-point selection, summarization prompt
 * construction, and compacted message assembly.
 *
 * This service contains the pure algorithm and prompt-construction logic.
 * It does NOT call any LLM or platform — COMP-02 handles model invocation.
 *
 * Preparation algorithm:
 *   1. Estimate total tokens from model-facing text (no JSON).
 *   2. Walk backward from newest to oldest, accumulating tokens until
 *      keepRecentTokens is reached.
 *   3. Move the boundary to a safe cut point.
 *   4. Return rich result or skip reason.
 *
 * Safe cut rules:
 *   - Prefer cutting before a user message.
 *   - Never retain a tool result whose assistant tool call was summarized away.
 *   - Never summarize an assistant tool-call message while retaining its tool results.
 *   - Assistant/tool-call groups are indivisible.
 *   - If no safe boundary exists, skip compaction.
 *
 * Token estimation uses only model-facing text/content with a
 * Unicode-safe 3.25 chars/token divisor. No JSON envelope is included.
 *
 * Tool results in the summarize partition are deterministically
 * digested/placeholdered before summarization so the model call never
 * receives huge raw tool output.
 */
final class SessionCompactor
{
    /** Characters-per-token divisor for estimation */
    private const float CHARS_PER_TOKEN = 3.25;

    /** Maximum preview snippet length for tool-result digests */
    private const int TOOL_DIGEST_PREVIEW_LENGTH = 500;

    /** Prefix/suffix markers for the compacted summary injection */
    private const string SUMMARY_PREFIX = "The conversation history before this point was compacted into the following handoff summary. Use it as prior context, not as a new user request.\n\n<summary>\n";
    private const string SUMMARY_SUFFIX = "\n</summary>";

    private readonly AgentMessageToolCallSequenceValidator $sequenceValidator;

    public function __construct(
        private readonly CompactionPromptBuilder $promptBuilder,
        ?AgentMessageToolCallSequenceValidator $sequenceValidator = null,
    ) {
        $this->sequenceValidator = $sequenceValidator ?? new AgentMessageToolCallSequenceValidator();
    }

    // ── Preparation ───────────────────────────────────────────────────

    /**
     * Prepare compaction partitions for a message list.
     *
     * Returns a rich result object — either a usable preparation or
     * a specific skip reason — so the future pipeline (COMP-02) can
     * distinguish disabled/below-threshold/no-safe-boundary without
     * inspecting bare null.
     *
     * @param list<AgentMessage> $messages Current message list
     * @param CompactionConfig   $settings Compaction settings (budgets, enabled flag)
     */
    public function prepare(array $messages, CompactionConfig $settings): CompactionPreparationResultDTO
    {
        $count = \count($messages);

        // Nothing to compact with 0 or 1 messages.
        if ($count < 2) {
            return CompactionPreparationResultDTO::skipped(
                CompactionSkipReasonEnum::TooFewMessages,
            );
        }

        $totalEstimate = $this->estimateTokens($messages);

        // No need to compact if the entire session fits within keepRecentTokens.
        if ($totalEstimate <= $settings->keepRecentTokens) {
            return CompactionPreparationResultDTO::skipped(
                CompactionSkipReasonEnum::BelowKeepRecentTokens,
            );
        }

        // Walk backward accumulating tokens until we retain at least keepRecentTokens.
        $boundary = $this->findBoundary($messages, $settings->keepRecentTokens);

        if (null === $boundary) {
            return CompactionPreparationResultDTO::skipped(
                CompactionSkipReasonEnum::NoBoundary,
            );
        }

        // Move the boundary to a safe cut point.
        $safeBoundary = $this->findSafeBoundary($messages, $boundary);

        if (null === $safeBoundary) {
            return CompactionPreparationResultDTO::skipped(
                CompactionSkipReasonEnum::NoSafeBoundary,
            );
        }

        $messagesToSummarize = \array_slice($messages, 0, $safeBoundary);
        $retainedTail = \array_slice($messages, $safeBoundary);

        $priorSummaryPresent = $this->detectPriorCompactSummary($messagesToSummarize);

        return CompactionPreparationResultDTO::ready(
            new CompactionPreparationDTO(
                messagesToSummarize: $messagesToSummarize,
                retainedTailMessages: $retainedTail,
                tokenEstimateBefore: $totalEstimate,
                messagesCompacted: \count($messagesToSummarize),
                messagesRetained: \count($retainedTail),
                firstRetainedIndex: $safeBoundary,
                priorSummaryPresent: $priorSummaryPresent,
            ),
        );
    }

    // ── Prompt construction ───────────────────────────────────────────

    /**
     * Build the message list for the summarization LLM call.
     *
     * Returns a list of AgentMessage instances ready for the model.
     * Messages in the summarize partition are digested (tool results
     * replaced with deterministic placeholders) before appending the
     * summarization prompt.
     *
     * The summarization prompt is rendered from COMPACTION.md template
     * files (same precedence as SYSTEM.md). Custom instructions are
     * injected via the {custom_instructions_part} placeholder.
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
        $renderedPrompt = $this->promptBuilder->build($customInstructions);

        $promptMessage = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $renderedPrompt]],
        );

        $digestedMessages = $this->digestToolResults($preparation->messagesToSummarize);

        return [
            ...$digestedMessages,
            $promptMessage,
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
     * Estimates from model-facing text/content only, using
     * Symfony UnicodeString length and a 3.25 chars/token divisor.
     * No JSON envelope is included; metadata, details, and structural
     * keys are not counted.
     *
     * Public so the future auto-compaction trigger policy (COMP-06)
     * can check whether the estimated context exceeds the reserve
     * threshold.
     *
     * @param list<AgentMessage> $messages
     */
    public function estimateTokens(array $messages): int
    {
        $total = 0;

        foreach ($messages as $message) {
            $total += $this->estimateMessageTokens($message);
        }

        return $total;
    }

    // ── Tool-result digest ────────────────────────────────────────────

    /**
     * Transform tool messages into deterministic digest/placeholder content.
     *
     * Only tool results are replaced; all other messages pass through
     * unchanged. The original session messages are never mutated — this
     * operates on a copy of the summarize partition.
     *
     * The digest includes: tool name, tool_call_id, is_error, estimated
     * tokens, char count, and a bounded first/last text preview.
     *
     * @param list<AgentMessage> $messages
     *
     * @return list<AgentMessage>
     */
    private function digestToolResults(array $messages): array
    {
        $result = [];

        foreach ($messages as $message) {
            if ('tool' !== $message->role) {
                $result[] = $message;

                continue;
            }

            $result[] = $this->buildToolDigest($message);
        }

        return $result;
    }

    /**
     * Build a deterministic digest AgentMessage for a tool result.
     */
    private function buildToolDigest(AgentMessage $toolMessage): AgentMessage
    {
        $originalText = $this->messageToText($toolMessage);
        $charCount = (new UnicodeString($originalText))->length();

        $lines = explode("\n", $originalText);
        $preview = '';

        if ($charCount <= self::TOOL_DIGEST_PREVIEW_LENGTH * 2) {
            $preview = $originalText;
        } else {
            $first = mb_substr($originalText, 0, self::TOOL_DIGEST_PREVIEW_LENGTH);
            $last = mb_substr($originalText, -self::TOOL_DIGEST_PREVIEW_LENGTH);
            $preview = $first."\n\n... [".($charCount - self::TOOL_DIGEST_PREVIEW_LENGTH * 2).' chars truncated] ...'."\n\n".$last;
        }

        $status = $toolMessage->isError ? 'ERROR' : 'ok';
        $exitCode = null;

        if (\is_array($toolMessage->details) && isset($toolMessage->details['exit_code'])) {
            $exitCode = $toolMessage->details['exit_code'];
            if (0 !== $exitCode) {
                $status = 'exit code '.$exitCode;
            }
        }

        $digestText = \sprintf(
            "[Tool result: %s]\ntool_call_id: %s\nstatus: %s\nestimated tokens: %d\nchar count: %d\n\n--- content preview ---\n%s\n--- end preview ---",
            $toolMessage->toolName ?? 'unknown',
            $toolMessage->toolCallId ?? '(none)',
            $status,
            $this->estimateMessageTokens($toolMessage),
            $charCount,
            $preview,
        );

        return new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $digestText]],
            toolCallId: $toolMessage->toolCallId,
            toolName: $toolMessage->toolName,
        );
    }

    /**
     * Estimate tokens for a single AgentMessage using model-facing
     * text only.
     */
    private function estimateMessageTokens(AgentMessage $message): int
    {
        $text = $this->messageToText($message);

        if ('' === $text) {
            return 0;
        }

        $length = (new UnicodeString($text))->length();

        return (int) ceil($length / self::CHARS_PER_TOKEN);
    }

    /**
     * Extract the model-facing text content from an AgentMessage.
     *
     * Follows AgentMessageConverter semantics:
     *   - Concatenates non-empty text content parts with newline.
     *   - For custom roles, includes the `[role] ` prefix.
     *   - For tool messages, uses the text content that would be sent
     *     (not details.raw_result).
     *   - Does not include metadata, details, or JSON envelope.
     */
    private function messageToText(AgentMessage $message): string
    {
        $text = $this->extractContentText($message->content);

        if ($message->isCustomRole()) {
            $text = \sprintf('[%s] %s', $message->role, $text);
        }

        return $text;
    }

    /**
     * Concatenate text from all 'text' content parts.
     *
     * @param array<int, array<string, mixed>> $content
     */
    private function extractContentText(array $content): string
    {
        $parts = [];

        foreach ($content as $contentPart) {
            if (!\is_array($contentPart)) {
                continue;
            }

            $text = $contentPart['text'] ?? null;
            if (\is_string($text) && '' !== $text) {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
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
     * Returns null if all messages fit inside the target.
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

        return null;
    }

    /**
     * Find a safe boundary at or before the given tentative boundary.
     *
     * Walks backward from tentativeBoundary, preferring cuts before user
     * messages. Falls back to any safe cut point if no user boundary exists
     * within range.
     *
     * @param list<AgentMessage> $messages
     * @param int                $tentativeBoundary Index of the first retained message
     *
     * @return int|null A safe boundary index, or null if none found
     */
    private function findSafeBoundary(array $messages, int $tentativeBoundary): ?int
    {
        if ($tentativeBoundary < 1) {
            return null;
        }

        $fallback = null;

        for ($candidate = $tentativeBoundary; $candidate >= 1; --$candidate) {
            if (!$this->isSafeCutPoint($messages, $candidate)) {
                continue;
            }

            if ('user' === $messages[$candidate]->role) {
                return $candidate;
            }

            if (null === $fallback) {
                $fallback = $candidate;
            }
        }

        return $fallback;
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

        // Check summarize partition's assistant tool_call_ids against
        // retained tool results. If a summarized assistant declared a
        // call whose result landed in the retained tail, the assistant
        // was summarized away but its result survived — forbidden split.
        foreach ($summarizeToolCallIds as $toolCallId => $_unused) {
            for ($i = $boundary; $i < $count; ++$i) {
                if ('tool' === $messages[$i]->role && $messages[$i]->toolCallId === $toolCallId) {
                    return false;
                }
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
