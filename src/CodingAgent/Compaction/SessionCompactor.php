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
 *   1. Extract immutable prologue (leading system/user-context messages).
 *   2. Estimate total tokens from model-facing text on the compactable body (no JSON).
 *   3. Walk backward from newest to oldest, accumulating tokens until
 *      keepRecentTokens is reached.
 *   4. Move the boundary to a safe cut point (bounded user-boundary preference).
 *   5. Reassemble: prologue + body prefix → summarization; prologue + body tail → retained.
 *   6. Return rich result or skip reason.
 *
 * Safe cut rules:
 *   - Prefer cutting before a user message within a bounded search window.
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

    // ── Preparation ───────────────────────────────────────────────────

    /**
     * Maximum consecutive leading messages with role `system` or
     * `user-context` that are treated as immutable prologue and
     * excluded from compaction.
     */
    private const int MAX_PROLOGUE_MESSAGES = 16;

    public function __construct(
        private readonly CompactionTokenEstimator $tokenEstimator,
        private readonly ToolResultDigestService $digestService,
        private readonly CompactionBoundarySelector $boundarySelector,
        private readonly CompactionPromptBuilder $promptBuilder,
    ) {
    }

    /**
     * Prepare compaction partitions for a message list.
     *
     * Extracts the immutable prologue (leading `system` and `user-context`
     * messages) before boundary selection.  The prologue is never summarized
     * or replaced — it remains at the front of every compacted message list
     * for prompt-cache locality and correctness.
     *
     * Boundary selection runs only on the compactable body (messages after
     * the prologue).  The summarization LLM sees only body messages plus the
     * summarization prompt; it is never asked to summarize system prompts or
     * injected agent-instruction context.
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
        // ── Extract immutable prologue ────────────────────────────
        //
        // Leading `system` and `user-context` messages carry injected
        // agent instructions, skills, AGENTS.md context, etc.  These
        // are not part of the conversation and must never be summarized
        // away or replaced.  They remain at the front of every compacted
        // message list for prompt-cache locality.
        $prologueCount = 0;
        $prologue = [];
        $maxPrologue = min(self::MAX_PROLOGUE_MESSAGES, \count($messages));
        for ($i = 0; $i < $maxPrologue; ++$i) {
            $role = $messages[$i]->role;
            if ('system' === $role || 'user-context' === $role) {
                $prologue[] = $messages[$i];
                ++$prologueCount;
            } else {
                break;
            }
        }

        // Compactable body starts after the prologue.
        $body = \array_slice($messages, $prologueCount);
        $bodyCount = \count($body);

        // Nothing to compact with 0 or 1 body messages.
        if ($bodyCount < 2) {
            return CompactionPreparationResultDTO::skipped(
                CompactionSkipReasonEnum::TooFewMessages,
            );
        }

        $totalEstimate = $this->tokenEstimator->estimateTokens($body);

        // No need to compact if the compactable body fits within keepRecentTokens.
        if ($totalEstimate <= $settings->keepRecentTokens) {
            return CompactionPreparationResultDTO::skipped(
                CompactionSkipReasonEnum::BelowKeepRecentTokens,
            );
        }

        // Walk backward accumulating tokens until we retain at least keepRecentTokens.
        $boundary = $this->boundarySelector->findBoundary($body, $settings->keepRecentTokens);

        if (null === $boundary) {
            return CompactionPreparationResultDTO::skipped(
                CompactionSkipReasonEnum::NoBoundary,
            );
        }

        // Move the boundary to a safe cut point.
        $safeBoundary = $this->boundarySelector->findSafeBoundary($body, $boundary);

        if (null === $safeBoundary) {
            return CompactionPreparationResultDTO::skipped(
                CompactionSkipReasonEnum::NoSafeBoundary,
            );
        }

        // Compactable region: body messages before the safe boundary.
        $messagesToSummarize = \array_slice($body, 0, $safeBoundary);

        // Retained region: prologue (immutable) + body tail after boundary.
        $retainedBodyTail = \array_slice($body, $safeBoundary);
        $retainedTail = [...$prologue, ...$retainedBodyTail];

        // firstRetainedIndex is the global index (prologue offset + body cut)
        // pointing to the first non-prologue message in the retained tail.
        $globalFirstRetainedIndex = $prologueCount + $safeBoundary;

        $priorSummaryPresent = $this->detectPriorCompactSummary($messagesToSummarize);

        return CompactionPreparationResultDTO::ready(
            new CompactionPreparationDTO(
                messagesToSummarize: $messagesToSummarize,
                retainedTailMessages: $retainedTail,
                tokenEstimateBefore: $totalEstimate,
                messagesCompacted: \count($messagesToSummarize),
                messagesRetained: \count($retainedTail),
                firstRetainedIndex: $globalFirstRetainedIndex,
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
     * The assembly order is:
     *   [prologue..., summaryMessage, retainedBodyTail...]
     *
     * Prologue (leading system/user-context messages embedded as a prefix
     * in retainedTailMessages) is extracted and placed before the summary
     * so the full system prompt and agent-instruction context remain at the
     * front for prompt-cache locality.
     *
     * Returns a CompactResultDTO containing:
     *   - The full compacted message list
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

        // Separate immutable prologue from the retained body tail.
        // Prologue messages (system / user-context) travel as a prefix
        // in retainedTailMessages and are re-placed before the summary.
        $prologue = [];
        $bodyTail = [];
        $inPrologue = true;

        foreach ($preparation->retainedTailMessages as $msg) {
            if ($inPrologue && ('system' === $msg->role || 'user-context' === $msg->role)) {
                $prologue[] = $msg;
            } else {
                $inPrologue = false;
                $bodyTail[] = $msg;
            }
        }

        $compactedMessages = [
            ...$prologue,
            $summaryMessage,
            ...$bodyTail,
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
