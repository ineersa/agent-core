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
     *
     * @return list<RunEvent>
     */
    public function filter(array $events): array
    {
        $history = $this->projector->build($events);

        return $this->filterSortedAtPosition($events, $history, $history->positionTurnNo);
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    public function filterAtPosition(array $events, int $positionTurnNo): array
    {
        $history = $this->projector->build($events);

        return $this->filterSortedAtPosition($events, $history, $positionTurnNo);
    }

    /**
     * One projection + one sort per public call. Both issue #183 suppressions apply:
     * mapped discarded-turn seeding commands, and unmatched post-completion pending launches.
     *
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    private function filterSortedAtPosition(array $events, HistoryDTO $history, int $positionTurnNo): array
    {
        $retainedTurnNos = $history->retainedTurnNosThrough($positionTurnNo);

        $sorted = $events;
        usort($sorted, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        $commandSeqToCreatedTurn = $this->buildCommandSeqToCreatedTurnMap($sorted);
        $unmatchedPendingCommandSeqs = $this->buildUnmatchedPendingCommandSeqs(
            $sorted,
            $positionTurnNo,
            $commandSeqToCreatedTurn,
        );

        $filtered = [];
        foreach ($sorted as $event) {
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

        return $filtered;
    }

    /**
     * @param list<RunEvent> $sortedEvents already sorted by seq
     *
     * @return array<int, int>
     */
    private function buildCommandSeqToCreatedTurnMap(array $sortedEvents): array
    {
        $pendingCommandSeqs = [];
        $commandSeqToCreatedTurn = [];

        foreach ($sortedEvents as $event) {
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
     * @param list<RunEvent>  $sortedEvents            already sorted by seq
     * @param array<int, int> $commandSeqToCreatedTurn
     *
     * @return array<int, true>
     */
    private function buildUnmatchedPendingCommandSeqs(
        array $sortedEvents,
        int $positionTurnNo,
        array $commandSeqToCreatedTurn,
    ): array {
        if ($positionTurnNo <= 0) {
            return [];
        }

        $historySelectSeq = 0;
        foreach ($sortedEvents as $event) {
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
        foreach ($sortedEvents as $event) {
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
        foreach ($sortedEvents as $event) {
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
}
