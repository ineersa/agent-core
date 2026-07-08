<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Compaction\SessionCompactor;
use Ineersa\CodingAgent\Config\CompactionConfig;

/**
 * Fork virtual-compaction adapter.
 *
 * Delegates partition selection to {@see SessionCompactor}. When a prior
 * compact_summary exists, carries it forward without an LLM call. Otherwise
 * uses {@see ForkSnapshotSummarizerInterface} for fork-local summarization
 * (same prompt/partition semantics as /compact, without mutating parent state).
 *
 * Token budget is sourced from CompactionConfig::keepRecentTokens.
 *
 * Never mutates its input — always returns a new message list.
 */
final readonly class ForkSnapshotCompactor
{
    public function __construct(
        private SessionCompactor $sessionCompactor,
        private ForkSnapshotSummarizerInterface $summarizer,
    ) {
    }

    /**
     * Virtually compact a sanitized message list for fork consumption.
     *
     * @param list<AgentMessage> $sanitized          Sanitized parent messages
     * @param CompactionConfig   $config             Compaction settings (budget from keepRecentTokens)
     * @param string|null        $activeSessionModel Parent session model for summarization overrides
     *
     * @return ForkCompactionResult Compacted result (no-op when under budget or summarization skipped)
     */
    public function compact(array $sanitized, CompactionConfig $config, ?string $activeSessionModel = null): ForkCompactionResult
    {
        if ([] === $sanitized) {
            return new ForkCompactionResult(
                messages: [],
                compacted: false,
            );
        }

        $result = $this->sessionCompactor->prepare($sanitized, $config);

        if (!$result->isReady()) {
            return new ForkCompactionResult(
                messages: \array_slice($sanitized, 0),
                compacted: false,
            );
        }

        $prep = $result->preparation;

        if ($prep->priorSummaryPresent) {
            $summaryText = $this->extractMostRecentCompactSummaryText($prep->messagesToSummarize);
            if (null === $summaryText || '' === trim($summaryText)) {
                return new ForkCompactionResult(
                    messages: \array_slice($sanitized, 0),
                    compacted: false,
                );
            }
        } else {
            try {
                $summaryText = $this->summarizer->summarize($prep, $activeSessionModel);
            } catch (ForkCompactionSummarizationException $exception) {
                throw new ForkCompactionSummarizationException('Fork launch requires compacted parent context but summarization failed: '.$exception->getMessage(), previous: $exception);
            }
        }

        $compact = $this->sessionCompactor->buildCompactedMessages($summaryText, $prep);

        return new ForkCompactionResult(
            messages: $compact->compactedMessages,
            compacted: true,
            summaryText: $summaryText,
            summarizedCount: $compact->messagesCompacted,
        );
    }

    /**
     * @param list<AgentMessage> $messages
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
