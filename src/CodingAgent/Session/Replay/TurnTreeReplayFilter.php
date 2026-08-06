<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\TurnTree\TurnTreeProjector;

/**
 * Filters a canonical event stream to only active linear-history events.
 *
 * Uses {@see TurnTreeProjector} to determine retained active turns, then includes:
 *  - Run-level events (turnNo === 0, e.g. run_started)
 *  - Events whose turnNo is in the active retained list up to the target tip
 *  - History metadata events (leaf_set, history_tail_discarded, legacy turn_branched)
 *
 * Discarded/abandoned turn events stay in events.jsonl but are excluded from
 * hot prompt, transcript, and RunState rebuild.
 */
final class TurnTreeReplayFilter
{
    public function __construct(
        private readonly TurnTreeProjector $projector,
    ) {
    }

    /**
     * @param list<RunEvent> $events
     */
    public function filter(string $runId, array $events): TurnBranchReplayDTO
    {
        $tree = $this->projector->build($runId, $events);

        return $this->filterForLeaf($runId, $events, $tree->currentLeafTurnNo);
    }

    /**
     * @param list<RunEvent> $events
     */
    public function filterForLeaf(string $runId, array $events, ?int $targetLeafTurnNo = null): TurnBranchReplayDTO
    {
        $tree = $this->projector->build($runId, $events);

        if (null === $targetLeafTurnNo) {
            $targetLeafTurnNo = $tree->currentLeafTurnNo;
        }

        // Active path through target tip (prefix of linear history).
        $activePathTurnNos = null !== $targetLeafTurnNo && [] !== $tree->nodesByTurnNo
            ? TurnTreeProjector::activePathTo($targetLeafTurnNo, $tree->nodesByTurnNo)
            : [];

        // When positioned before a selected user prompt (leaf_set on previous
        // boundary), seed commands that create the next discarded/unselected
        // turn must not resurrect that turn's prompt into hot state.
        $commandSeqToCreatedTurn = $this->buildCommandSeqToCreatedTurnMap($events);

        $canonicalEventCount = \count($events);
        $canonicalLastSeq = $this->maxSeq($events);

        $filtered = [];
        foreach ($events as $event) {
            if (0 === $event->turnNo) {
                $filtered[] = $event;
                continue;
            }

            if (\in_array($event->turnNo, $activePathTurnNos, true)) {
                if ($this->shouldExcludeTurnSeedingCommand($event, $commandSeqToCreatedTurn, $activePathTurnNos)) {
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

        return new TurnBranchReplayDTO(
            events: $filtered,
            canonicalEventCount: $canonicalEventCount,
            canonicalLastSeq: $canonicalLastSeq,
            activePathTurnNos: $activePathTurnNos,
            currentLeafTurnNo: $targetLeafTurnNo,
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
     * @param list<int>       $activePathTurnNos
     */
    private function shouldExcludeTurnSeedingCommand(
        RunEvent $event,
        array $commandSeqToCreatedTurn,
        array $activePathTurnNos,
    ): bool {
        if (!$this->isTurnSeedingCommandEvent($event)) {
            return false;
        }

        $createdTurnNo = $commandSeqToCreatedTurn[$event->seq] ?? null;
        if (null === $createdTurnNo) {
            return false;
        }

        return !\in_array($createdTurnNo, $activePathTurnNos, true);
    }

    private function isHistoryMetadataEvent(RunEvent $event): bool
    {
        return \in_array($event->type, [
            RunEventTypeEnum::LeafSet->value,
            RunEventTypeEnum::HistoryTailDiscarded->value,
            RunEventTypeEnum::TurnBranched->value,
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
