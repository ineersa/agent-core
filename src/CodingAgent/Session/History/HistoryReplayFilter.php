<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

/**
 * Filters canonical events to the retained linear-history prefix at a position.
 *
 * Includes:
 *  - run-level events (turnNo === 0)
 *  - events for retained turns through the position
 *  - history metadata (history_position_set, history_tail_discarded)
 *
 * Suppresses turn-seeding commands whose created turn is outside the retained
 * prefix (prevents resurrecting abandoned prompts / issue #183 hangs).
 *
 * Also suppresses unmatched post-completion AgentCommandQueued/Applied events on
 * the selected position that sit after the last AgentEnd/LlmStepCompleted and
 * before the latest history_position_set(reason=history_select) for that position
 * when no later TurnAdvanced maps them. Those pending launches are the unfinished
 * seed of a discarded later turn (including compaction/interrupted/CAS recovery
 * via rebuildIfStale) and must not leave RunState Running.
 */
final class HistoryReplayFilter
{
    public function __construct(
        private readonly HistoryProjector $projector,
    ) {
    }

    /**
     * @param list<RunEvent> $events
     */
    public function filter(string $runId, array $events): HistoryReplayResultDTO
    {
        $history = $this->projector->build($runId, $events);

        return $this->filterAtPosition($runId, $events, $history->positionTurnNo);
    }

    /**
     * @param list<RunEvent> $events
     */
    public function filterAtPosition(string $runId, array $events, ?int $positionTurnNo = null): HistoryReplayResultDTO
    {
        $history = $this->projector->build($runId, $events);

        // null = current selected position; 0 = before first retained turn.
        if (null === $positionTurnNo) {
            $positionTurnNo = $history->positionTurnNo;
        }

        $retainedTurnNos = $history->retainedTurnNosThrough($positionTurnNo);
        $commandSeqToCreatedTurn = $this->buildCommandSeqToCreatedTurnMap($events);
        $unmatchedPendingCommandSeqs = $this->buildUnmatchedPendingCommandSeqs(
            $events,
            $positionTurnNo,
            $commandSeqToCreatedTurn,
        );

        $canonicalEventCount = \count($events);
        $canonicalLastSeq = $this->maxSeq($events);

        // Streams without turn_advanced (unit fixtures / pre-first-turn) have no
        // projected turns; pass the full ordered stream so hot-prompt rebuild still
        // sees content. Once any turn_advanced exists, retained-prefix rules apply.
        if (!$this->streamHasTurnAdvanced($events)) {
            $sorted = $events;
            usort($sorted, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

            return new HistoryReplayResultDTO(
                events: $sorted,
                canonicalEventCount: $canonicalEventCount,
                canonicalLastSeq: $canonicalLastSeq,
                retainedTurnNos: $retainedTurnNos,
                positionTurnNo: $positionTurnNo,
            );
        }

        $filtered = [];
        foreach ($events as $event) {
            if (0 === $event->turnNo) {
                $filtered[] = $event;
                continue;
            }

            if (\in_array($event->turnNo, $retainedTurnNos, true)) {
                if ($this->shouldExcludeTurnSeedingCommand($event, $commandSeqToCreatedTurn, $retainedTurnNos)) {
                    continue;
                }
                if (isset($unmatchedPendingCommandSeqs[$event->seq])) {
                    continue;
                }
                $filtered[] = $event;
                continue;
            }

            if ($this->isHistoryMetadataEvent($event)) {
                $filtered[] = $event;
            }
        }

        usort($filtered, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return new HistoryReplayResultDTO(
            events: $filtered,
            canonicalEventCount: $canonicalEventCount,
            canonicalLastSeq: $canonicalLastSeq,
            retainedTurnNos: $retainedTurnNos,
            positionTurnNo: $positionTurnNo,
        );
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return array<int, int>
     */
    private function buildCommandSeqToCreatedTurnMap(array $events): array
    {
        $sorted = $events;
        usort($sorted, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        $pendingCommandSeqs = [];
        $commandSeqToCreatedTurn = [];

        foreach ($sorted as $event) {
            if ($this->isTurnSeedingCommandEvent($event)) {
                $pendingCommandSeqs[] = $event->seq;
                continue;
            }

            if (RunEventTypeEnum::TurnAdvanced->value !== $event->type) {
                continue;
            }

            $createdTurnNo = (int) ($event->payload['turn_no'] ?? $event->turnNo);
            if ($createdTurnNo <= 0) {
                continue;
            }

            foreach ($pendingCommandSeqs as $commandSeq) {
                $commandSeqToCreatedTurn[$commandSeq] = $createdTurnNo;
            }
            $pendingCommandSeqs = [];
        }

        return $commandSeqToCreatedTurn;
    }

    /**
     * Pending commands after completion on the selected position, before the latest
     * history_select marker, with no later TurnAdvanced mapping (discarded launch).
     * Applies to rebuildAtPosition and rebuildIfStale crash recovery alike.
     *
     * @param list<RunEvent>  $events
     * @param array<int, int> $commandSeqToCreatedTurn
     *
     * @return array<int, true>
     */
    private function buildUnmatchedPendingCommandSeqs(
        array $events,
        ?int $positionTurnNo,
        array $commandSeqToCreatedTurn,
    ): array {
        if (null === $positionTurnNo || $positionTurnNo <= 0) {
            return [];
        }

        $sorted = $events;
        usort($sorted, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        $historySelectSeq = 0;
        foreach ($sorted as $event) {
            if (RunEventTypeEnum::HistoryPositionSet->value !== $event->type) {
                continue;
            }

            $payload = $event->payload;
            $eventPosition = (int) ($payload['position_turn_no'] ?? 0);
            $reason = \is_string($payload['reason'] ?? null) ? $payload['reason'] : '';

            if ($eventPosition !== $positionTurnNo || 'history_select' !== $reason) {
                continue;
            }

            $historySelectSeq = max($historySelectSeq, $event->seq);
        }

        if (0 === $historySelectSeq) {
            return [];
        }

        $turnCompletionSeq = 0;
        foreach ($sorted as $event) {
            if ($event->turnNo !== $positionTurnNo || $event->seq >= $historySelectSeq) {
                continue;
            }

            if (\in_array($event->type, [
                RunEventTypeEnum::AgentEnd->value,
                RunEventTypeEnum::LlmStepCompleted->value,
            ], true)) {
                $turnCompletionSeq = max($turnCompletionSeq, $event->seq);
            }
        }

        if (0 === $turnCompletionSeq) {
            return [];
        }

        $exclude = [];
        foreach ($sorted as $event) {
            if ($event->turnNo !== $positionTurnNo) {
                continue;
            }
            if (!$this->isTurnSeedingCommandEvent($event)) {
                continue;
            }
            if ($event->seq <= $turnCompletionSeq || $event->seq >= $historySelectSeq) {
                continue;
            }
            // Mapped commands are already handled by shouldExcludeTurnSeedingCommand;
            // this set is only for unmatched (no next TurnAdvanced) pending launches.
            if (isset($commandSeqToCreatedTurn[$event->seq])) {
                continue;
            }
            $exclude[$event->seq] = true;
        }

        return $exclude;
    }

    /**
     * @param list<RunEvent> $events
     */
    private function streamHasTurnAdvanced(array $events): bool
    {
        foreach ($events as $event) {
            if (RunEventTypeEnum::TurnAdvanced->value === $event->type) {
                return true;
            }
        }

        return false;
    }

    private function isTurnSeedingCommandEvent(RunEvent $event): bool
    {
        return \in_array($event->type, [
            RunEventTypeEnum::AgentCommandQueued->value,
            RunEventTypeEnum::AgentCommandApplied->value,
        ], true);
    }

    /**
     * @param array<int, int> $commandSeqToCreatedTurn
     * @param list<int>       $retainedTurnNos
     */
    private function shouldExcludeTurnSeedingCommand(
        RunEvent $event,
        array $commandSeqToCreatedTurn,
        array $retainedTurnNos,
    ): bool {
        if (!$this->isTurnSeedingCommandEvent($event)) {
            return false;
        }

        $createdTurnNo = $commandSeqToCreatedTurn[$event->seq] ?? null;
        if (null === $createdTurnNo) {
            return false;
        }

        return !\in_array($createdTurnNo, $retainedTurnNos, true);
    }

    private function isHistoryMetadataEvent(RunEvent $event): bool
    {
        return \in_array($event->type, [
            RunEventTypeEnum::HistoryPositionSet->value,
            RunEventTypeEnum::HistoryTailDiscarded->value,
        ], true);
    }

    /**
     * @param list<RunEvent> $events
     */
    private function maxSeq(array $events): int
    {
        if ([] === $events) {
            return 0;
        }

        return (int) max(array_map(static fn (RunEvent $event): int => $event->seq, $events));
    }
}
