<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\TurnTree\TurnTreeProjector;

/**
 * Projects the canonical event stream onto the selected turn branch.
 *
 * Direct shell commands are identified by their canonical shell-command anchor
 * and tool_call_id. This keeps model-generated bash and run-level lifecycle
 * events in the normal branch projection while removing an abandoned shell's
 * command and any lifecycle event carrying its ID.
 */
final class TurnTreeReplayFilter
{
    public function __construct(
        private readonly TurnTreeProjector $projector,
        private readonly RewindBoundaryPolicy $rewindBoundaryPolicy,
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
        $targetLeafTurnNo ??= $tree->currentLeafTurnNo;
        $activePathTurnNos = null !== $targetLeafTurnNo && [] !== $tree->nodesByTurnNo
            ? TurnTreeProjector::activePathTo($targetLeafTurnNo, $tree->nodesByTurnNo)
            : [];
        $boundary = $this->rewindBoundaryPolicy->forTarget($events, $targetLeafTurnNo ?? 0);
        [$shellIds, $activeShellIds] = $this->shellIds($events, $activePathTurnNos, $targetLeafTurnNo, $boundary);
        $abandonedShellIds = array_diff_key($shellIds, $activeShellIds);
        $commandSeqToCreatedTurn = $this->buildCommandSeqToCreatedTurnMap($events);

        $filtered = [];
        foreach ($events as $event) {
            $toolCallId = $event->payload['tool_call_id'] ?? null;
            if (\is_string($toolCallId) && isset($abandonedShellIds[$toolCallId])) {
                continue;
            }

            if ($this->rewindBoundaryPolicy->isAbandonedTargetCommand($event, $targetLeafTurnNo ?? 0, $boundary)) {
                continue;
            }

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
     * @param list<RunEvent>                            $events
     * @param list<int>                                 $activePathTurnNos
     * @param array{rewindSeq: int, completionSeq: int} $boundary
     *
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function shellIds(
        array $events,
        array $activePathTurnNos,
        ?int $targetTurnNo,
        array $boundary,
    ): array {
        $all = [];
        $active = [];

        foreach ($events as $event) {
            if (!$this->isShellAnchor($event)) {
                continue;
            }

            $toolCallId = $event->payload['tool_call_id'] ?? null;
            if (!\is_string($toolCallId) || '' === $toolCallId) {
                continue;
            }

            $all[$toolCallId] = true;
            if ((0 === $event->turnNo || \in_array($event->turnNo, $activePathTurnNos, true))
                && !$this->rewindBoundaryPolicy->isAbandonedTargetCommand($event, $targetTurnNo ?? 0, $boundary)) {
                $active[$toolCallId] = true;
            }
        }

        return [$all, $active];
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

        $pending = [];
        $created = [];
        foreach ($sorted as $event) {
            if ($this->isTurnSeedingCommandEvent($event)) {
                $pending[] = $event->seq;

                continue;
            }

            if (RunEventTypeEnum::TurnAdvanced->value !== $event->type) {
                continue;
            }

            $turnNo = (int) ($event->payload['turn_no'] ?? $event->turnNo);
            if ($turnNo <= 0) {
                continue;
            }

            foreach ($pending as $seq) {
                $created[$seq] = $turnNo;
            }
            $pending = [];
        }

        return $created;
    }

    private function isShellAnchor(RunEvent $event): bool
    {
        return RunEventTypeEnum::AgentCommandApplied->value === $event->type
            && 'shell_command' === ($event->payload['kind'] ?? null);
    }

    private function isTurnSeedingCommandEvent(RunEvent $event): bool
    {
        return !$this->isShellAnchor($event)
            && \in_array($event->type, [
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
        $createdTurnNo = $commandSeqToCreatedTurn[$event->seq] ?? null;

        return null !== $createdTurnNo && !\in_array($createdTurnNo, $activePathTurnNos, true);
    }

    private function isTreeMetadataEvent(RunEvent $event): bool
    {
        return \in_array($event->type, [
            RunEventTypeEnum::LeafSet->value,
            RunEventTypeEnum::TurnBranched->value,
        ], true);
    }

    /** @param list<RunEvent> $events */
    private function maxSeq(array $events): int
    {
        if ([] === $events) {
            return 0;
        }

        return (int) max(array_map(static fn (RunEvent $event): int => $event->seq, $events));
    }
}
