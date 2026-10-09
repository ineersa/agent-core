<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeTransportException;
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
 * Live RuntimeEventPoller applies events after the owner-produced bootstrap.
 * History-position transcript blocks are
 * assigned wholesale from SessionTranscriptProviderInterface, not from this projector.
 *
 * Wire array {@code subagent_progress} is denormalized once here before the typed
 * snapshot reaches {@see SubagentLiveCatalog}. Malformed present payloads fail visibly.
 */
final readonly class TuiRuntimeEventApplier
{
    private TuiBootstrapAssembler $bootstrap;

    public function __construct(
        private TranscriptProjectorInterface $projector,
        private DenormalizerInterface $denormalizer,
    ) {
        $this->bootstrap = new TuiBootstrapAssembler($denormalizer);
    }

    /** Protocol events never enter the ordinary transcript reducer. */
    public function applyBootstrap(TuiSessionState $state, AgentSessionClient $client, RuntimeEvent $event, ?callable $onMounted): bool
    {
        if (!\in_array($event->type, ['bootstrap.available', 'bootstrap.frame', 'bootstrap.end', 'session.ready'], true)) {
            return false;
        }
        $mounted = $this->bootstrap->accept($state, $event);
        if (null === $mounted) {
            return true;
        }
        if (null === $onMounted) {
            throw new RuntimeTransportException('Bootstrap requires a mounted transcript consumer.');
        }
        $data = $mounted['resume'];
        $status = $data['status'] ?? null;
        if (!\is_string($status) || null === \Ineersa\AgentCore\Domain\Run\RunStatus::tryFrom($status)
            || !\is_bool($data['is_shell_run'] ?? null) || !\is_int($data['turn_no'] ?? null)
            || $data['turn_no'] < 0 || !\is_array($data['usage'] ?? null) || !\is_array($data['queued_messages'] ?? null)) {
            throw new RuntimeTransportException('Invalid bootstrap resume metadata.');
        }
        $usage = new UsageProjection();
        foreach (['inputTokens', 'outputTokens', 'turnOutputTokens', 'latestInputTokens', 'cacheReadTokens', 'cacheCreationTokens'] as $key) {
            if (!\is_int($data['usage'][$key] ?? null) || $data['usage'][$key] < 0) {
                throw new RuntimeTransportException('Invalid bootstrap usage metadata.');
            }
            $usage->$key = $data['usage'][$key];
        }
        if (!\is_bool($data['usage']['hasCacheTelemetry'] ?? null) || !is_numeric($data['usage']['totalCost'] ?? null)) {
            throw new RuntimeTransportException('Invalid bootstrap cost metadata.');
        }
        $usage->hasCacheTelemetry = $data['usage']['hasCacheTelemetry'];
        $usage->totalCost = (float) $data['usage']['totalCost'];
        // Digit-only identities become integer PHP array keys after JSON decoding.
        foreach ($data['queued_messages'] as $text) {
            if (!\is_string($text)) {
                throw new RuntimeTransportException('Invalid bootstrap pending message.');
            }
        }
        $catalog = new SubagentLiveCatalog();
        foreach ($mounted['blocks'] as $block) {
            $progress = $block->meta['subagent_progress'] ?? null;
            if (\is_array($progress)) {
                $progress = $this->denormalizer->denormalize($progress, SubagentProgressSnapshotInterface::class);
            }
            if ($progress instanceof SubagentProgressSnapshotInterface) {
                $catalog->ingestSnapshot($progress);
            }
        }
        // No partial blocks, usage, catalog or durable cursor become visible before
        // checksum validation and metadata decoding have both succeeded.
        $this->hydrateProjectedTranscript($mounted['blocks']);
        $state->replaceTranscript($mounted['blocks']);
        $state->usage = $usage;
        $state->subagentLiveCatalog = $catalog;
        $state->queuedUserMessages = $data['queued_messages'];
        $state->isShellRun = $data['is_shell_run'];
        $state->activity = match ($status) {
            'completed' => RunActivityStateEnum::Completed,
            'cancelled' => RunActivityStateEnum::Cancelled,
            'failed' => RunActivityStateEnum::Failed,
            default => RunActivityStateEnum::Idle,
        };
        $state->isCompacting = false;
        $state->lastSeq = $mounted['cut']->canonicalSeq;
        $state->bootstrapMounted = true;
        $onMounted();
        $client->acknowledgeBootstrap($mounted['cut']->toArray());

        return true;
    }

    public function releaseBootstrap(): void
    {
        $this->bootstrap->release();
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
            $state->queuedFollowUps = [];
            $state->pendingEditorRestoreText = null;
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
            || (RuntimeEventTypeEnum::CommandRejected->value === $event->type
                && 'compact' === ($event->payload['commandType'] ?? null)
                && RunActivityStateEnum::Compacting !== $state->activity)
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
            $state->isCompacting = false;
        }

        if (\in_array($event->type, [RuntimeEventTypeEnum::RunFailed->value, RuntimeEventTypeEnum::TurnFailed->value], true)
            && [] !== $state->queuedFollowUps) {
            // Failure must not start another turn or leave hidden input that a
            // later compaction could dispatch. Return it to the user's editor.
            $state->pendingEditorRestoreText = implode("\n\n", $state->queuedFollowUps);
            $state->queuedFollowUps = [];
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
