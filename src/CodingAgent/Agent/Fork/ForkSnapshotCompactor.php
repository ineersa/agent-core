<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Compaction\CompactionBoundarySelector;

/**
 * Default fork snapshot compactor.
 *
 * Adapted from Pi's injectVirtualCompaction in fork.ts but without
 * observational-memory (OM) support.  In v1, compaction works purely
 * through cut-point selection and carried-forward summary reuse.
 *
 * The compactor never mutates its input — always returns a new message list.
 */
final readonly class ForkSnapshotCompactor implements ForkSnapshotCompactorInterface
{
    public function __construct(
        private CompactionBoundarySelector $boundarySelector,
        private ForkSnapshotSummaryProviderInterface $summaryProvider,
    ) {
    }

    /**
     * Virtually compact a sanitized message list.
     *
     * Steps:
     *   1. If messages fit within keepRecentTokens, return unchanged (no compaction).
     *   2. If they exceed, find a safe cut via findSafeBoundary.
     *   3. Retain the tail after the cut.
     *   4. Carry forward the most recent prior compaction summary found in the
     *      discarded prefix, prepended to the retained tail.
     *   5. Leave room for OM / richer summaries via ForkSnapshotSummaryProviderInterface.
     *
     * {@inheritdoc}
     */
    public function compact(array $sanitized, int $keepRecentTokens): ForkCompactionResult
    {
        if ([] === $sanitized) {
            return new ForkCompactionResult(
                messages: [],
                compacted: false,
            );
        }

        // Check whether messages exceed the budget.
        $tentativeBoundary = $this->boundarySelector->findBoundary($sanitized, $keepRecentTokens);

        // No compaction needed — all messages fit.
        if (null === $tentativeBoundary) {
            // No compaction needed. Still optionally check for prior summary to carry.
            $priorSummary = $this->findPriorCompactSummary($sanitized, -1);

            if (null !== $priorSummary) {
                // Already has a summary embedded — return as-is.
                return new ForkCompactionResult(
                    messages: \array_slice($sanitized, 0),
                    compacted: false,
                );
            }

            return new ForkCompactionResult(
                messages: \array_slice($sanitized, 0),
                compacted: false,
            );
        }

        // Find a safe cut point.
        $safeBoundary = $this->boundarySelector->findSafeBoundary($sanitized, $tentativeBoundary);

        if (null === $safeBoundary || $safeBoundary < 1) {
            // No safe boundary found — return unchanged.
            return new ForkCompactionResult(
                messages: \array_slice($sanitized, 0),
                compacted: false,
            );
        }

        // Split: discarded = [0..safeBoundary-1], retained = [safeBoundary..]
        $discarded = \array_slice($sanitized, 0, $safeBoundary);
        $retained = \array_slice($sanitized, $safeBoundary);

        // Find the most recent prior compaction summary in the discarded prefix.
        $priorSummary = $this->findPriorCompactSummary($sanitized, $safeBoundary);

        // Ask the summary provider for a summary.
        $summaryText = $this->summaryProvider->summarizeForSnapshot($discarded, $priorSummary);

        // If no summary text could be produced, return the retained messages as-is.
        if (null === $summaryText || '' === trim($summaryText)) {
            return new ForkCompactionResult(
                messages: \array_slice($sanitized, 0),
                compacted: false,
            );
        }

        // Build a summary message with compact_summary metadata (same pattern as SessionCompactor).
        $summaryPrefix = 'The following is a summary of the earlier conversation that has been compacted for context:\n\n';
        $summarySuffix = '\n\n---\n\n';
        $summaryMessage = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $summaryPrefix.$summaryText.$summarySuffix]],
            metadata: ['compact_summary' => true],
        );

        $compactedMessages = [
            $summaryMessage,
            ...$retained,
        ];

        return new ForkCompactionResult(
            messages: $compactedMessages,
            compacted: true,
            summaryText: $summaryText,
            summarizedCount: \count($discarded),
        );
    }

    /**
     * Find the most recent prior compact_summary message text.
     *
     * Scans up to (but not including) $upToIndex; if $upToIndex < 0, scans
     * all messages.
     *
     * @param list<AgentMessage> $messages
     * @param int                $upToIndex Exclusive upper bound index
     *
     * @return string|null The most recent compact_summary text, or null
     */
    private function findPriorCompactSummary(array $messages, int $upToIndex): ?string
    {
        $count = \count($messages);
        $effectiveEnd = $upToIndex < 0 ? $count : $upToIndex;

        for ($i = $effectiveEnd - 1; $i >= 0; --$i) {
            if (true === ($messages[$i]->metadata['compact_summary'] ?? null)) {
                // Extract text content from the summary message.
                $text = '';
                foreach ($messages[$i]->content as $part) {
                    if (\is_array($part) && isset($part['text']) && \is_string($part['text'])) {
                        $text .= $part['text'];
                    }
                }

                if ('' !== $text) {
                    return $text;
                }
            }
        }

        return null;
    }
}
