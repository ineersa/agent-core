<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Compaction\CompactionPreparationDTO;
use Ineersa\CodingAgent\Compaction\CompactionSkipReasonEnum;
use Ineersa\CodingAgent\Compaction\SessionCompactor;
use Ineersa\CodingAgent\Config\CompactionConfig;

/**
 * Fork virtual-compaction adapter.
 *
 * Every fork launch produces a compacted child context for the current parent
 * snapshot. Unlike /compact, fork does not skip when the body is under budget
 * or when a prior compact_summary exists — it always runs fork-local
 * summarization (or a trivial single-message summary) before child start.
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
     * @return ForkCompactionResult Compacted result for child StartRunInput
     */
    public function compact(array $sanitized, CompactionConfig $config, ?string $activeSessionModel = null): ForkCompactionResult
    {
        if ([] === $sanitized) {
            return new ForkCompactionResult(
                messages: [],
                compacted: false,
            );
        }

        $preparation = $this->resolveForkPreparation($sanitized, $config);

        try {
            $summaryText = $this->summarizer->summarize($preparation, $activeSessionModel);
        } catch (ForkCompactionSummarizationException $exception) {
            throw new ForkCompactionSummarizationException('Fork launch requires compacted parent context but summarization failed: '.$exception->getMessage(), hint: $exception->hint() ?? 'Check compaction.model, parent session model, and LLM availability, then retry fork.', previous: $exception);
        }

        $compact = $this->sessionCompactor->buildCompactedMessages($summaryText, $preparation);

        return new ForkCompactionResult(
            messages: $compact->compactedMessages,
            compacted: true,
            summaryText: $summaryText,
            summarizedCount: $compact->messagesCompacted,
        );
    }

    /**
     * Build a fork-specific compaction partition for the current snapshot.
     *
     * Uses {@see SessionCompactor::prepare()} when it would compact normally.
     * When /compact would skip (under budget, too few messages, no boundary),
     * synthesizes a preparation that still summarizes the full compactable body
     * so the child never receives raw multi-message transcript.
     *
     * @param list<AgentMessage> $sanitized
     */
    private function resolveForkPreparation(array $sanitized, CompactionConfig $config): CompactionPreparationDTO
    {
        $result = $this->sessionCompactor->prepare($sanitized, $config);

        if ($result->isReady()) {
            return $result->preparation;
        }

        return $this->buildForcedForkPreparation($sanitized, $result->skipReason);
    }

    /**
     * @param list<AgentMessage> $sanitized
     */
    private function buildForcedForkPreparation(array $sanitized, ?CompactionSkipReasonEnum $skipReason): CompactionPreparationDTO
    {
        $prologueCount = 0;
        $prologue = [];
        $totalMessages = \count($sanitized);
        for ($i = 0; $i < $totalMessages; ++$i) {
            $role = $sanitized[$i]->role;
            if ('system' === $role || 'user-context' === $role) {
                $prologue[] = $sanitized[$i];
                ++$prologueCount;
            } else {
                break;
            }
        }

        $body = \array_slice($sanitized, $prologueCount);
        $bodyCount = \count($body);

        if (0 === $bodyCount) {
            throw new ForkCompactionSummarizationException('Fork launch requires compacted parent context but no compactable messages remain after prologue extraction.');
        }

        if (1 === $bodyCount) {
            return new CompactionPreparationDTO(
                messagesToSummarize: $body,
                retainedTailMessages: $prologue,
                tokenEstimateBefore: 1,
                messagesCompacted: 1,
                messagesRetained: \count($prologue),
                firstRetainedIndex: $prologueCount,
                priorSummaryPresent: $this->bodyContainsPriorCompactSummary($body),
            );
        }

        if (CompactionSkipReasonEnum::TooFewMessages === $skipReason) {
            return new CompactionPreparationDTO(
                messagesToSummarize: $body,
                retainedTailMessages: $prologue,
                tokenEstimateBefore: max(2, $bodyCount),
                messagesCompacted: $bodyCount,
                messagesRetained: \count($prologue),
                firstRetainedIndex: $prologueCount,
                priorSummaryPresent: $this->bodyContainsPriorCompactSummary($body),
            );
        }

        $retainedTail = [...$prologue, $body[\count($body) - 1]];
        $messagesToSummarize = \array_slice($body, 0, -1);

        if ([] === $messagesToSummarize) {
            $messagesToSummarize = $body;
            $retainedTail = $prologue;
        }

        return new CompactionPreparationDTO(
            messagesToSummarize: $messagesToSummarize,
            retainedTailMessages: $retainedTail,
            tokenEstimateBefore: max(2, \count($sanitized)),
            messagesCompacted: \count($messagesToSummarize),
            messagesRetained: \count($retainedTail),
            firstRetainedIndex: $prologueCount + \count($body) - 1,
            priorSummaryPresent: $this->bodyContainsPriorCompactSummary($messagesToSummarize),
        );
    }

    /**
     * @param list<AgentMessage> $body
     */
    private function bodyContainsPriorCompactSummary(array $body): bool
    {
        foreach ($body as $message) {
            if (true === ($message->metadata['compact_summary'] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
