<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Compaction\SessionCompactor;
use Ineersa\CodingAgent\Config\CompactionConfig;

/**
 * Fork virtual-compaction adapter.
 *
 * Delegates partition selection and summary-message assembly to
 * {@see SessionCompactor}, carrying forward only the most recent
 * prior compact_summary text without calling an LLM.
 *
 * The token budget is sourced from CompactionConfig::keepRecentTokens
 * (compaction.keep_recent_tokens), not a fork-specific setting.
 *
 * Adapted from Pi's injectVirtualCompaction in fork.ts but without
 * observational-memory (OM) support (FORK-05 will add the LLM-backed
 * summary provider).
 *
 * Never mutates its input — always returns a new message list.
 */
final readonly class ForkSnapshotCompactor
{
    public function __construct(
        private SessionCompactor $sessionCompactor,
    ) {
    }

    /**
     * Virtually compact a sanitized message list for fork consumption.
     *
     * @param list<AgentMessage> $sanitized Sanitized parent messages
     * @param CompactionConfig   $config    Compaction settings (budget from keepRecentTokens)
     *
     * @return ForkCompactionResult Compacted result (may be no-op if under budget or no prior summary)
     */
    public function compact(array $sanitized, CompactionConfig $config): ForkCompactionResult
    {
        if ([] === $sanitized) {
            return new ForkCompactionResult(
                messages: [],
                compacted: false,
            );
        }

        // Delegate boundary selection + prologue extraction to SessionCompactor.
        $result = $this->sessionCompactor->prepare($sanitized, $config);

        if (!$result->isReady()) {
            // SessionCompactor::prepare() returned a skip reason:
            //   TooFewMessages / BelowKeepRecentTokens / NoBoundary / NoSafeBoundary
            return new ForkCompactionResult(
                messages: \array_slice($sanitized, 0),
                compacted: false,
            );
        }

        $prep = $result->preparation;

        // v1 bail: there is no carried-forward compact_summary in the discarded
        // prefix and no LLM is available to synthesize one (LLM-backed provider
        // arrives in FORK-05). Return the sanitized messages UNCHANGED so the
        // child receives the full (uncompacted) parent tail.
        if (!$prep->priorSummaryPresent) {
            return new ForkCompactionResult(
                messages: \array_slice($sanitized, 0),
                compacted: false,
            );
        }

        // Extract the most recent prior compact_summary text from the
        // partition that SessionCompactor marked for summarization.
        $priorText = $this->extractMostRecentCompactSummaryText($prep->messagesToSummarize);

        if (null === $priorText || '' === trim($priorText)) {
            return new ForkCompactionResult(
                messages: \array_slice($sanitized, 0),
                compacted: false,
            );
        }

        // Delegate summary-message assembly to SessionCompactor, which
        // handles the <summary> wrapper (its private constants are the
        // single source of truth), compact_summary metadata, and correct
        // prologue placement (system/user-context before the summary).
        $compact = $this->sessionCompactor->buildCompactedMessages($priorText, $prep);

        return new ForkCompactionResult(
            messages: $compact->compactedMessages,
            compacted: true,
            summaryText: $priorText,
            summarizedCount: $compact->messagesCompacted,
        );
    }

    /**
     * Extract the most recent compact_summary text from a message list.
     *
     * Scans backward for the most recent user message with
     * metadata['compact_summary'] === true and returns its concatenated
     * text content, or null if none is found / has no text.
     *
     * This is fork-specific: SessionCompactor::detectPriorCompactSummary()
     * returns only a bool; the fork needs the actual text to carry forward.
     *
     * @param list<AgentMessage> $messages Messages to scan (typically messagesToSummarize)
     *
     * @return string|null The concatenated text, or null
     */
    private function extractMostRecentCompactSummaryText(array $messages): ?string
    {
        for ($i = \count($messages) - 1; $i >= 0; --$i) {
            if (true === ($messages[$i]->metadata['compact_summary'] ?? null)) {
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
