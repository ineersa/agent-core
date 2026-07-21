<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\TurnTree\TurnTreeProjector;

/**
 * Filters a canonical event stream to only the events on the active turn branch path.
 *
 * Uses {@see TurnTreeProjector} to determine the active branch, then includes:
 *  - Run-level events (turnNo === 0, e.g. run_started)
 *  - Events whose turnNo is in the active branch path
 *  - Tree metadata events (leaf_set, turn_branched)
 *
 * Abandoned sibling branch events (message, tool, assistant content for turns
 * not on the active path) are excluded. Direct bang shell interactions are
 * branch-owned by an explicit tool_call_id, rather than inferred from
 * tool_name=bash, so model-generated bash calls remain unaffected.
 * The canonical stream remains intact; only the replay view is filtered.
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

        return $this->filterForLeaf($runId, $events, $tree->currentLeafTurnNo);
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

        $commandSeqToCreatedTurn = $this->buildCommandSeqToCreatedTurnMap($events);
        $rewindBoundary = $this->rewindBoundaryForTarget($events, $targetLeafTurnNo);
        $activeDirectShellToolCallIds = $this->activeDirectShellToolCallIds(
            $events,
            $activePathTurnNos,
            $targetLeafTurnNo,
            $rewindBoundary,
        );

        $filtered = [];
        foreach ($events as $event) {
            if ($this->isAbandonedDirectShellEvent(
                $event,
                $activePathTurnNos,
                $targetLeafTurnNo,
                $rewindBoundary,
                $activeDirectShellToolCallIds,
            )) {
                continue;
            }

            // Include run-level events (turn 0, e.g. run_started). This is
            // deliberately after the direct-shell check: correlated direct shell
            // lifecycle events may also be stamped run-level for a root shell run.
            if (0 === $event->turnNo) {
                $filtered[] = $event;
                continue;
            }

            // Include events on the target leaf's path.
            if (\in_array($event->turnNo, $activePathTurnNos, true)) {
                if ($this->shouldExcludeTurnSeedingCommand($event, $commandSeqToCreatedTurn, $activePathTurnNos)) {
                    continue;
                }

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

        usort($filtered, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return new TurnBranchReplayDTO(
            events: $filtered,
            canonicalEventCount: \count($events),
            canonicalLastSeq: $this->maxSeq($events),
            activePathTurnNos: $activePathTurnNos,
            currentLeafTurnNo: $targetLeafTurnNo,
        );
    }

    /**
     * Maps turn-seeding agent_command_* seq to the turn_advanced turn they create.
     *
     * Follow-up commands are stamped with the originating turn's turnNo while the
     * run is still on that turn; the subsequent turn_advanced creates the new turn.
     *
     * @param list<RunEvent> $events
     *
     * @return array<int, int> command event seq => created turn number
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
        // shell_command is not turn-seeding: bangs attach to the current leaf
        // without creating a child turn via turn_advanced.
        if (RunEventTypeEnum::AgentCommandApplied->value === $event->type
            && 'shell_command' === ($event->payload['kind'] ?? null)) {
            return false;
        }

        return \in_array($event->type, [
            RunEventTypeEnum::AgentCommandQueued->value,
            RunEventTypeEnum::AgentCommandApplied->value,
        ], true);
    }

    /**
     * Find the boundary used when replaying a target immediately after rewind.
     *
     * Commands submitted on the target turn after its last completion but before
     * the rewind leaf_set are abandoned input, not part of the target turn's
     * conversational transcript. This is the same boundary used by run-state
     * replay for normal follow-up commands.
     *
     * @param list<RunEvent> $events
     *
     * @return array{rewindSeq:int, completionSeq:int}
     */
    private function rewindBoundaryForTarget(array $events, ?int $targetTurnNo): array
    {
        if (null === $targetTurnNo || $targetTurnNo <= 0) {
            return ['rewindSeq' => 0, 'completionSeq' => 0];
        }

        $rewindSeq = 0;
        foreach ($events as $event) {
            if (RunEventTypeEnum::LeafSet->value !== $event->type) {
                continue;
            }

            if ('rewind' !== ($event->payload['reason'] ?? null)
                || (int) ($event->payload['turn_no'] ?? 0) !== $targetTurnNo) {
                continue;
            }

            $rewindSeq = max($rewindSeq, $event->seq);
        }

        if (0 === $rewindSeq) {
            return ['rewindSeq' => 0, 'completionSeq' => 0];
        }

        $completionSeq = 0;
        foreach ($events as $event) {
            if ($event->turnNo !== $targetTurnNo || $event->seq >= $rewindSeq) {
                continue;
            }

            if (\in_array($event->type, [
                RunEventTypeEnum::AgentEnd->value,
                RunEventTypeEnum::LlmStepCompleted->value,
            ], true)) {
                $completionSeq = max($completionSeq, $event->seq);
            }
        }

        return ['rewindSeq' => $rewindSeq, 'completionSeq' => $completionSeq];
    }

    /**
     * @param list<RunEvent>                          $events
     * @param list<int>                               $activePathTurnNos
     * @param array{rewindSeq:int, completionSeq:int} $rewindBoundary
     *
     * @return array<string, true>
     */
    private function activeDirectShellToolCallIds(
        array $events,
        array $activePathTurnNos,
        ?int $targetTurnNo,
        array $rewindBoundary,
    ): array {
        $ids = [];

        foreach ($events as $event) {
            if (RunEventTypeEnum::AgentCommandApplied->value !== $event->type
                || 'shell_command' !== ($event->payload['kind'] ?? null)) {
                continue;
            }

            if (!$this->isActiveDirectShellCommand($event, $activePathTurnNos, $targetTurnNo, $rewindBoundary)) {
                continue;
            }

            $toolCallId = $event->payload['tool_call_id'] ?? null;
            if (\is_string($toolCallId) && '' !== $toolCallId) {
                $ids[$toolCallId] = true;
            }
        }

        return $ids;
    }

    /**
     * @param list<int>                               $activePathTurnNos
     * @param array{rewindSeq:int, completionSeq:int} $rewindBoundary
     */
    private function isActiveDirectShellCommand(
        RunEvent $event,
        array $activePathTurnNos,
        ?int $targetTurnNo,
        array $rewindBoundary,
    ): bool {
        // Root shell-only input has no conversational turn to rewind before.
        if (0 === $event->turnNo) {
            return true;
        }

        if (!\in_array($event->turnNo, $activePathTurnNos, true)) {
            return false;
        }

        // A rewind to this exact turn means the target is the state at its last
        // completed model step. Direct shell input after that step is abandoned,
        // even though it shares the target turn number.
        if ($event->turnNo === $targetTurnNo
            && $rewindBoundary['completionSeq'] > 0
            && $event->seq > $rewindBoundary['completionSeq']
            && $event->seq < $rewindBoundary['rewindSeq']) {
            return false;
        }

        return true;
    }

    /**
     * @param list<int>                               $activePathTurnNos
     * @param array{rewindSeq:int, completionSeq:int} $rewindBoundary
     * @param array<string, true>                     $activeDirectShellToolCallIds
     */
    private function isAbandonedDirectShellEvent(
        RunEvent $event,
        array $activePathTurnNos,
        ?int $targetTurnNo,
        array $rewindBoundary,
        array $activeDirectShellToolCallIds,
    ): bool {
        $isCommand = RunEventTypeEnum::AgentCommandApplied->value === $event->type
            && 'shell_command' === ($event->payload['kind'] ?? null);
        $isLifecycle = \in_array($event->type, [
            RunEventTypeEnum::ToolExecutionStart->value,
            RunEventTypeEnum::ToolExecutionUpdate->value,
            RunEventTypeEnum::ToolExecutionEnd->value,
        ], true) && true === ($event->payload['direct_shell'] ?? false);

        if (!$isCommand && !$isLifecycle) {
            return false;
        }

        $toolCallId = $event->payload['tool_call_id'] ?? null;
        if (\is_string($toolCallId) && '' !== $toolCallId) {
            return !isset($activeDirectShellToolCallIds[$toolCallId]);
        }

        // New direct-shell events are correlated. Keep old/unusual uncorrelated
        // root events as run-level content; otherwise use explicit turn membership.
        return !$this->isActiveDirectShellCommand($event, $activePathTurnNos, $targetTurnNo, $rewindBoundary);
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
            // Command has not yet created a new turn (e.g. active leaf input).
            return false;
        }

        return !\in_array($createdTurnNo, $activePathTurnNos, true);
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
