<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Config\CompactionConfig;

/**
 * Compaction orchestration, summarization prompt construction, and
 * compacted message assembly.
 *
 * This service delegates token estimation to {@see CompactionTokenEstimator},
 * safe cut-point selection to {@see CompactionBoundarySelector}, and
 * tool-result digesting to {@see ToolResultDigestService}.
 *
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
 */
final class SessionCompactor
{
    /** Prefix/suffix markers for the compacted summary injection */
    private const string SUMMARY_PREFIX = "The conversation history before this point was compacted into the following handoff summary. Use it as prior context, not as a new user request.\n\n<summary>\n";
    private const string SUMMARY_SUFFIX = "\n</summary>";

    public function __construct(
        private readonly CompactionTokenEstimator $tokenEstimator,
        private readonly ToolResultDigestService $digestService,
        private readonly CompactionBoundarySelector $boundarySelector,
        private readonly CompactionPromptBuilder $promptBuilder,
    ) {
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

        $totalEstimate = $this->tokenEstimator->estimateTokens($messages);

        // No need to compact if the entire session fits within keepRecentTokens.
        if ($totalEstimate <= $settings->keepRecentTokens) {
            return CompactionPreparationResultDTO::skipped(
                CompactionSkipReasonEnum::BelowKeepRecentTokens,
            );
        }

        // Walk backward accumulating tokens until we retain at least keepRecentTokens.
        $boundary = $this->boundarySelector->findBoundary($messages, $settings->keepRecentTokens);

        if (null === $boundary) {
            return CompactionPreparationResultDTO::skipped(
                CompactionSkipReasonEnum::NoBoundary,
            );
        }

        // Move the boundary to a safe cut point.
        $safeBoundary = $this->boundarySelector->findSafeBoundary($messages, $boundary);

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
     * Tool results in the summarize partition are replaced with
     * deterministic digest/placeholders before appending the
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

        $digestedMessages = $this->digestService->digestToolResults($preparation->messagesToSummarize);

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

        $tokenEstimateAfter = $this->tokenEstimator->estimateTokens($compactedMessages);

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
}
