<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

/**
 * Reduces non-transcript TUI session state from runtime events.
 *
 * Live RuntimeEventPoller and SessionInitializer branch-aware resume call this
 * for each active-path replay event so usage, activity, queued messages, and
 * subagent catalog match live processing. Leaf transcript blocks are assigned
 * wholesale from SessionTranscriptProviderInterface, not from this projector.
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
            // Reset live projector for post-leaf events in the same poll batch.
            // Leaf transcript blocks are assigned wholesale by RuntimeEventPoller
            // from SessionTranscriptProvider (isolated projector).
            $this->projector->reset();

            $state->activity = RunActivityStateEnum::Idle;
            $state->queuedFollowUp = null;
            // Abandoned-branch queued steer/follow-up commands must not keep rendering
            // as pending after rewind/resume to an earlier leaf.
            $state->queuedUserMessages = [];

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

        if (\in_array($event->type, [
            RuntimeEventTypeEnum::RunCancelled->value,
            RuntimeEventTypeEnum::TurnCancelled->value,
            RuntimeEventTypeEnum::RunFailed->value,
            RuntimeEventTypeEnum::TurnFailed->value,
        ], true)) {
            // Cancel/fail terminals drop any still-pending queued commands from the
            // ending turn; they will not be applied on the abandoned path.
            $state->queuedUserMessages = [];
        }

        $state->applyQueuedUserMessageEvent($event);
        $state->subagentLiveCatalog->ingestRuntimeEvent($event);
        $this->projector->accept($event->toArray());
    }

    /** @return list<\Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock> */
    public function projectedBlocks(): array
    {
        return $this->projector->blocks();
    }
}
