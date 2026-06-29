<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Replay;

use Ineersa\AgentCore\Application\Dto\TurnBranchReplayDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\TurnTreeProjector;

/**
 * Filters a canonical event stream to only the events on the active turn branch path.
 *
 * Uses {@see TurnTreeProjector} to determine the active branch, then includes:
 *  - Run-level events (turnNo === 0, e.g. run_started)
 *  - Events whose turnNo is in the active branch path
 *  - Tree metadata events (leaf_set, turn_branched)
 *
 * Abandoned sibling branch events (message, tool, assistant content for turns
 * not on the active path) are excluded. The canonical stream remains intact;
 * only the replay view is filtered.
 */
final class TurnTreeReplayFilter
{
    public function __construct(
        private readonly TurnTreeProjector $projector,
    ) {
    }

    /**
     * Filter an unsorted event list to only active-branch events
     * (uses the project's current leaf from the event stream).
     *
     * Delegates to {@see filterForLeaf()} with the current leaf.
     *
     * @param list<RunEvent> $events Unsorted canonical events
     *
     * @return TurnBranchReplayDTO Filtered result with full-stream diagnostics
     */
    public function filter(string $runId, array $events): TurnBranchReplayDTO
    {
        $tree = $this->projector->build($runId, $events);
        $targetLeaf = $tree->currentLeafTurnNo;

        return $this->filterForLeaf($runId, $events, $targetLeaf);
    }

    /**
     * Filter an unsorted event list to only events on a specific branch path.
     *
     * Uses {@see TurnTreeProjector::activePathTo()} to compute the root-to-target
     * turn path, then includes:
     *  - Run-level events (turnNo === 0, e.g. run_started)
     *  - Events whose turnNo is in the active branch path
     *  - Tree metadata events (leaf_set, turn_branched)
     *
     * @param list<RunEvent> $events           Unsorted canonical events
     * @param int|null       $targetLeafTurnNo Target leaf turn number (null = use current leaf)
     *
     * @return TurnBranchReplayDTO Filtered result with full-stream diagnostics
     */
    public function filterForLeaf(string $runId, array $events, ?int $targetLeafTurnNo = null): TurnBranchReplayDTO
    {
        $tree = $this->projector->build($runId, $events);

        if (null === $targetLeafTurnNo) {
            $targetLeafTurnNo = $tree->currentLeafTurnNo;
        }

        // Use activePathTo for the target leaf (not the current leaf's path).
        $activePathTurnNos = null !== $targetLeafTurnNo && [] !== $tree->nodesByTurnNo
            ? TurnTreeProjector::activePathTo($targetLeafTurnNo, $tree->nodesByTurnNo)
            : [];

        $canonicalEventCount = \count($events);
        $canonicalLastSeq = $this->maxSeq($events);

        $filtered = [];
        foreach ($events as $event) {
            // Include run-level events (turn 0, e.g. run_started).
            if (0 === $event->turnNo) {
                $filtered[] = $event;
                continue;
            }

            // Include events on the target leaf's path.
            if (\in_array($event->turnNo, $activePathTurnNos, true)) {
                $filtered[] = $event;
                continue;
            }

            // Include tree metadata events (leaf_set, turn_branched) even
            // if their turnNo is not in the active path (defensive safety net).
            // These are no-op reducers during replay — they exist only for
            // future navigation/audit metadata and do not affect prompt
            // context or RunState reconstruction.
            if ($this->isTreeMetadataEvent($event)) {
                $filtered[] = $event;
            }
        }

        // Re-sort filtered events by seq for replay.
        usort($filtered, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return new TurnBranchReplayDTO(
            events: $filtered,
            canonicalEventCount: $canonicalEventCount,
            canonicalLastSeq: $canonicalLastSeq,
            activePathTurnNos: $activePathTurnNos,
            currentLeafTurnNo: $targetLeafTurnNo,
        );
    }

    private function isTreeMetadataEvent(RunEvent $event): bool
    {
        return \in_array($event->type, [
            RunEventTypeEnum::LeafSet->value,
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
