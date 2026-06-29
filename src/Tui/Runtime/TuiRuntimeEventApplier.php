<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

/**
 * Single reducer for replayable TUI session state from runtime events.
 *
 * Live RuntimeEventPoller and SessionInitializer::replayFromEvents() must both
 * call this for each accepted event so resume reconstructs the same visible
 * state as live processing (activity, usage, queued messages, transcript projection).
 */
final readonly class TuiRuntimeEventApplier
{
    public function __construct(
        private TranscriptProjectorInterface $projector,
    ) {
    }

    /**
     * @param bool $replayMode when true, per-turn timing uses replay-safe reset (no wall-clock t/s)
     */
    public function apply(TuiSessionState $state, RuntimeEvent $event, bool $replayMode = false): void
    {
        if (RuntimeEventTypeEnum::TurnStarted->value === $event->type) {
            if ($replayMode) {
                $state->usage->resetTurnForReplay();
            } else {
                $state->usage->resetTurn();
            }
        } elseif (RuntimeEventTypeEnum::AssistantMessageCompleted->value === $event->type) {
            $state->usage->accumulate($event);
        }

        if (RuntimeEventTypeEnum::RunLeafChanged->value === $event->type) {
            // Reset projector so replayTranscriptOnly (called by RuntimeEventPoller
            // after apply()) starts from a clean slate. The poller handles the actual
            // transcript rebuild by fetching active-path events and replaying them
            // through the projector only (no state mutation).
            $this->projector->reset();

            $state->activity = RunActivityStateEnum::Idle;
            $state->queuedFollowUp = null;

            return;
        }

        if (RuntimeEventTypeEnum::CompactionStarted->value === $event->type) {
            $state->isCompacting = true;
        } elseif (
            RuntimeEventTypeEnum::CompactionCompleted->value === $event->type
            || RuntimeEventTypeEnum::CompactionFailed->value === $event->type
        ) {
            $state->isCompacting = false;
        }

        $state->activity = ActivityStateMachine::transition($state->activity, $event);
        $state->applyQueuedUserMessageEvent($event);
        $this->projector->accept($event->toArray());
    }

    /**
     * Feed events through the projector for transcript rebuilding without touching state.
     *
     * The projector must already be reset (typically by the RunLeafChanged handler).
     * Used by RuntimeEventPoller to rebuild transcript blocks after a leaf change,
     * replaying only active-path events into the projector, then wholesale-replacing
     * $state->transcript with the result.
     *
     * @param list<RuntimeEvent> $runtimeEvents
     */
    public function replayTranscriptOnly(array $runtimeEvents): void
    {
        foreach ($runtimeEvents as $event) {
            $this->projector->accept($event->toArray());
        }
    }

    /** @return list<\Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock> */
    public function projectedBlocks(): array
    {
        return $this->projector->blocks();
    }
}
