<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Polls a selected child run id and projects readonly live transcript blocks.
 *
 * Canonical child history is applied once on every live-view entry via
 * {@see replaySnapshot()}. {@see poll()} consumes live
 * {@see AgentSessionClient::events()} for the child run id while the view is
 * active. Leaving or switching children resets the projector and pending buffers.
 *
 * The optional HITL callback mirrors RuntimeEventPoller so child
 * human_input.requested events can drive the shared QuestionCoordinator.
 */
final class SubagentLiveChildViewPoller
{
    private const float POLL_INTERVAL = 0.05;

    private readonly TuiRuntimeEventApplier $eventApplier;

    /** @var list<RuntimeEvent> */
    private array $pendingEvents = [];

    private bool $projectionReplaced = false;

    public function __construct(
        private readonly TranscriptProjectorInterface $projector,
        private readonly LoggerInterface $logger,
        DenormalizerInterface $denormalizer,
    ) {
        $this->eventApplier = new TuiRuntimeEventApplier($this->projector, $denormalizer);
    }

    public function resetProjection(): void
    {
        $this->projector->reset();
        $this->pendingEvents = [];
        $this->projectionReplaced = false;
    }

    /**
     * One-time apply of a child snapshot into live view state and the child projector.
     *
     * Call only while {@see SubagentLiveViewState::$active} is true (picker sets this before apply).
     * Reconstructs presentation from the durable snapshot and redispatches unresolved
     * canonical human_input.requested events.
     *
     * @param ?callable(RuntimeEvent): void $onHumanInputRequested
     *
     * @return list<TranscriptBlock>
     */
    public function replaySnapshot(
        SubagentLiveViewState $live,
        ChildRunTranscriptSnapshotDTO $snapshot,
        ?callable $onHumanInputRequested = null,
    ): array {
        if (!$live->active || null === $live->selected) {
            return $live->childTranscript();
        }

        $callbacks = $this->makeCallbacks($onHumanInputRequested);

        $this->resetProjection();

        $scratch = new TuiSessionState($live->selected->agentRunId);
        $scratch->activity = $live->childActivity;
        $scratch->queuedUserMessages = $live->childQueuedUserMessages;
        $scratch->llmRetryWorkingMessage = $live->llmRetryWorkingMessage;

        $pendingQuestions = [];
        foreach ($snapshot->replayEvents as $event) {
            $this->eventApplier->apply($scratch, $event, replayMode: true);
            $questionId = $event->payload['question_id'] ?? '';
            if (RuntimeEventTypeEnum::HumanInputRequested->value === $event->type) {
                $pendingQuestions[$questionId] = $event;
            } elseif (RuntimeEventTypeEnum::HumanInputAnswered->value === $event->type
                || RuntimeEventTypeEnum::HumanInputRejected->value === $event->type
            ) {
                unset($pendingQuestions[$questionId]);
            }
        }
        foreach ($pendingQuestions as $event) {
            $callbacks->dispatch($event, $live->selected->agentRunId);
        }

        $live->childActivity = $scratch->activity;
        $live->childQueuedUserMessages = $scratch->queuedUserMessages;
        $live->llmRetryWorkingMessage = $scratch->llmRetryWorkingMessage;
        $live->childLastSeq = $snapshot->maxSeq;
        $projected = $this->projector->blocks();
        $live->replaceChildTranscript(
            [] !== $projected ? $projected : $snapshot->transcriptBlocks,
            entryOnly: [] === $projected,
        );
        // Snapshot apply establishes the mounted baseline. Discard its full dirty
        // state so later live stream batches produce bounded incremental patches.
        $this->eventApplier->drainProjectedChanges();

        return $live->childTranscript();
    }

    /**
     * @param ?callable(RuntimeEvent): void $onHumanInputRequested
     *
     * @return TranscriptChangeSet|null Incremental transcript changes, or null when events changed only non-transcript state
     */
    public function poll(
        SubagentLiveViewState $live,
        AgentSessionClient $client,
        ?callable $onHumanInputRequested = null,
    ): ?TranscriptChangeSet {
        if (!$live->active || null === $live->selected) {
            return null;
        }

        $now = microtime(true);
        if (($now - $live->childLastPoll) < self::POLL_INTERVAL) {
            return null;
        }
        $live->childLastPoll = $now;

        if ([] !== $this->pendingEvents && $this->pendingEvents[0]->runId !== $live->selected->agentRunId) {
            $this->pendingEvents = [];
        }

        $events = [] !== $this->pendingEvents
            ? $this->pendingEvents
            : RuntimeEventCallbacks::eventList($client, $live->selected->agentRunId, $live->childLastSeq);
        if ([] === $events) {
            return null;
        }

        $changed = false;
        $scratch = new TuiSessionState($live->selected->agentRunId);
        $scratch->activity = $live->childActivity;
        $scratch->queuedUserMessages = $live->childQueuedUserMessages;
        $scratch->llmRetryWorkingMessage = $live->llmRetryWorkingMessage;

        $callbacks = $this->makeCallbacks($onHumanInputRequested);

        foreach ($events as $index => $event) {
            $seq = $event->seq;
            if (0 !== $seq && $seq <= $live->childLastSeq) {
                continue;
            }

            try {
                $this->eventApplier->apply($scratch, $event);
                if (RuntimeEventTypeEnum::RunHistoryPositionChanged->value === $event->type) {
                    $this->projectionReplaced = true;
                }
                $callbacks->dispatch($event, $live->selected->agentRunId);
            } catch (\Throwable $exception) {
                $this->pendingEvents = \array_slice($events, $index);

                throw $exception;
            }

            if (0 !== $seq) {
                $live->childLastSeq = $seq;
            }
            $changed = true;
        }
        $this->pendingEvents = [];

        if ($changed) {
            $live->childActivity = $scratch->activity;
            $live->childQueuedUserMessages = $scratch->queuedUserMessages;
            $live->llmRetryWorkingMessage = $scratch->llmRetryWorkingMessage;
        }

        if (!$changed) {
            return null;
        }

        $transcriptChanges = $this->eventApplier->drainProjectedChanges();
        // History selection resets the projector without emitting per-ID removals.
        // Only this structural boundary needs a complete snapshot, including tail
        // events from the same batch. Retain the flag across failed suffix retries.
        if ($this->projectionReplaced) {
            $transcriptChanges = TranscriptChangeSet::full($this->projector->blocks());
            $this->projectionReplaced = false;
        }
        $transcriptChanges = $live->applyTranscriptChangeSet($transcriptChanges);

        return $transcriptChanges->isEmpty() ? null : $transcriptChanges;
    }

    /**
     * @param ?callable(RuntimeEvent): void $onHumanInputRequested
     */
    private function makeCallbacks(
        ?callable $onHumanInputRequested,
    ): RuntimeEventCallbacks {
        return new RuntimeEventCallbacks(
            $this->logger,
            'SubagentLiveChildViewPoller event callback failed',
            'tui.subagent_live_child_poller',
            'subagent_live_child_poller.callback_failed',
            $onHumanInputRequested,
        );
    }
}
