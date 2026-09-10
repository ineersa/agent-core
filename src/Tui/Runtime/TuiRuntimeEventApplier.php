<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Reduces non-transcript TUI session state from runtime events.
 *
 * Live RuntimeEventPoller and SessionInitializer retained-history resume call this
 * for each retained-prefix replay event so usage, activity, queued messages, and
 * subagent catalog match live processing. History-position transcript blocks are
 * assigned wholesale from SessionTranscriptProviderInterface, not from this projector.
 *
 * Wire array {@code subagent_progress} is denormalized once here before the typed
 * snapshot reaches {@see SubagentLiveCatalog}. Malformed present payloads fail visibly.
 */
final readonly class TuiRuntimeEventApplier
{
    public function __construct(
        private TranscriptProjectorInterface $projector,
        private DenormalizerInterface $denormalizer,
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
        } elseif (RuntimeEventTypeEnum::LlmRequestRetrying->value === $event->type) {
            $attempt = \is_int($event->payload['attempt'] ?? null) ? $event->payload['attempt'] : 0;
            $maxAttempts = \is_int($event->payload['max_attempts'] ?? null) ? $event->payload['max_attempts'] : 0;
            $delayMs = \is_int($event->payload['delay_ms'] ?? null) ? $event->payload['delay_ms'] : 0;
            $reason = \is_string($event->payload['reason'] ?? null) && '' !== $event->payload['reason']
                ? $event->payload['reason']
                : 'LLM provider request failed.';
            $delayLabel = $delayMs >= 1000
                ? \sprintf('%.1fs', $delayMs / 1000)
                : \sprintf('%dms', $delayMs);
            $state->llmRetryWorkingMessage = \sprintf(
                'Retrying LLM %d/%d in %s — %s',
                max(1, $attempt),
                max(1, $maxAttempts),
                $delayLabel,
                mb_substr($reason, 0, 120),
            );
        } elseif (RuntimeEventTypeEnum::AssistantMessageStarted->value === $event->type
            || RuntimeEventTypeEnum::AssistantTextStarted->value === $event->type
            || RuntimeEventTypeEnum::AssistantThinkingStarted->value === $event->type
        ) {
            $state->llmRetryWorkingMessage = null;
        } elseif (RuntimeEventTypeEnum::AssistantMessageCompleted->value === $event->type) {
            $state->usage->accumulate($event);
        }

        if (RuntimeEventTypeEnum::RunHistoryPositionChanged->value === $event->type) {
            // Reset live projector for post-position events in the same poll batch.
            // Position transcript blocks are assigned wholesale by RuntimeEventPoller
            // from SessionTranscriptProvider (isolated projector).
            $this->projector->reset();

            $state->activity = RunActivityStateEnum::Idle;
            $state->queuedFollowUp = null;
            // Discarded-tail queued steer/follow-up commands must not keep rendering
            // as pending after history selection/resume to an earlier position.
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
            // ending turn; they will not be applied on the discarded tail.
            $state->queuedUserMessages = [];
            $state->llmRetryWorkingMessage = null;
        }

        // After terminal activity, ignore stale seq=0 assistant/tool stream
        // events entirely (queued messages, subagent progress, transcript).
        // Sequenced continuation events still flow normally.
        if ($state->activity->isTerminal()
            && 0 === $event->seq
            && (
                str_starts_with($event->type, 'assistant.')
                || str_starts_with($event->type, 'tool_call.')
                || str_starts_with($event->type, 'tool_execution.')
            )) {
            return;
        }

        $state->applyQueuedUserMessageEvent($event);
        $this->ingestSubagentProgress($state, $event);
        $this->projector->accept($event);
    }

    /**
     * Drain projector dirty changes for ordinary live polls.
     *
     * Prefer drainProjectedChanges() on the hot path so finalized
     * history is not re-materialized every tick.
     */
    public function drainProjectedChanges(): TranscriptChangeSet
    {
        return $this->projector->drainChanges();
    }

    /**
     * Hydrate the live projector from an isolated history-position snapshot.
     *
     * Does not mutate usage/activity. Clears projector dirty tracking so the
     * next drain only sees post-position events. Subsequent compaction can find
     * prior compaction.completed markers in the hydrated blocks.
     *
     * @param list<TranscriptBlock> $blocks
     */
    public function hydrateProjectedTranscript(array $blocks): void
    {
        $this->projector->replaceProjectedBlocks($blocks);
    }

    private function ingestSubagentProgress(TuiSessionState $state, RuntimeEvent $event): void
    {
        if (!str_contains($event->type, 'tool_execution')) {
            return;
        }

        if (!\array_key_exists('subagent_progress', $event->payload)) {
            return;
        }

        $progress = $event->payload['subagent_progress'];
        if (!\is_array($progress)) {
            throw new \InvalidArgumentException('subagent_progress payload must be an array when present.');
        }

        /** @var SubagentProgressSnapshotInterface $snapshot */
        $snapshot = $this->denormalizer->denormalize($progress, SubagentProgressSnapshotInterface::class);
        $state->subagentLiveCatalog->ingestSnapshot($snapshot);
    }
}
