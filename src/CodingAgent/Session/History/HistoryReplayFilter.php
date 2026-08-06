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

        $canonicalEventCount = \count($events);
        $canonicalLastSeq = $this->maxSeq($events);

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
