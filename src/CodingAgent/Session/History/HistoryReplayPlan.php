<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

/**
 * Scalar plan for a second streaming pass over the same canonical cut.
 *
 * Seeding commands mapped to abandoned anchors must not resurrect their prompts.
 * Unmatched commands after completion but before the latest history selection
 * are also abandoned launches, even when recorded on the selected turn (#183).
 * Compaction does not change the anchor or pending-command mapping.
 */
final readonly class HistoryReplayPlan
{
    /**
     * @param array<int, true> $retainedTurns
     * @param array<int, int>  $commandTurns
     * @param array<int, true> $unmatchedCommands
     */
    private function __construct(
        public int $positionTurnNo,
        private array $retainedTurns,
        private array $commandTurns,
        private array $unmatchedCommands,
    ) {
    }

    /** @param iterable<RunEvent> $events Chronological, unique canonical events. */
    public static function build(iterable $events, ?int $positionTurnNo = null): self
    {
        $history = new HistoryRetentionTracker();
        $lastSeq = null;
        $runId = null;
        $pending = [];
        $mapped = [];
        $selections = [];
        $completions = [];
        $completionBeforeSelection = [];
        foreach ($events as $event) {
            if ((null !== $lastSeq && $event->seq <= $lastSeq) || (null !== $runId && $runId !== $event->runId)) {
                throw new \RuntimeException('Replay planning requires chronological unique events from one run.');
            }
            $lastSeq = $event->seq;
            $runId = $event->runId;
            $history->observe($event);
            if (self::isCommand($event)) {
                $pending[$event->seq] = $event->turnNo;
            } elseif (RunEventTypeEnum::TurnAdvanced->value === $event->type) {
                $turn = (int) ($event->payload['turn_no'] ?? $event->turnNo);
                if ($turn > 0) {
                    foreach ($pending as $seq => $unusedTurn) {
                        $mapped[$seq] = $turn;
                    }
                    $pending = [];
                }
            } elseif (\in_array($event->type, [RunEventTypeEnum::AgentEnd->value, RunEventTypeEnum::LlmStepCompleted->value], true)) {
                $completions[$event->turnNo] = $event->seq;
            } elseif (RunEventTypeEnum::HistoryPositionSet->value === $event->type && 'history_select' === ($event->payload['reason'] ?? null)) {
                // The filter's selection fallback is deliberately 0, not event.turnNo.
                $turn = (int) ($event->payload['position_turn_no'] ?? 0);
                $selections[$turn] = $event->seq;
                $completionBeforeSelection[$turn] = $completions[$turn] ?? 0;
            }
        }
        $position = $positionTurnNo ?? $history->positionTurnNo;
        $retained = [];
        $anchors = new HistoryDTO($history->retainedTurnNos, [], $position);
        foreach ($anchors->retainedTurnNosThrough($position) as $turn) {
            $retained[$turn] = true;
        }
        $unmatched = [];
        $selection = $selections[$position] ?? 0;
        $completion = $completionBeforeSelection[$position] ?? 0;
        if ($position > 0 && $completion > 0) {
            foreach ($pending as $seq => $turn) {
                if ($turn === $position && $seq > $completion && $seq < $selection) {
                    $unmatched[$seq] = true;
                }
            }
        }

        return new self($position, $retained, $mapped, $unmatched);
    }

    public function includes(RunEvent $event): bool
    {
        // Run-level records bypass command suppression, matching the array API.
        if (0 === $event->turnNo) {
            return true;
        }
        if (isset($this->retainedTurns[$event->turnNo])) {
            if (self::isCommand($event) && isset($this->commandTurns[$event->seq]) && !isset($this->retainedTurns[$this->commandTurns[$event->seq]])) {
                return false;
            }

            return !isset($this->unmatchedCommands[$event->seq]);
        }

        return \in_array($event->type, [RunEventTypeEnum::HistoryPositionSet->value, RunEventTypeEnum::HistoryTailDiscarded->value], true);
    }

    private static function isCommand(RunEvent $event): bool
    {
        return \in_array($event->type, [RunEventTypeEnum::AgentCommandQueued->value, RunEventTypeEnum::AgentCommandApplied->value], true);
    }
}
