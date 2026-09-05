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
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;
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
 * Optional HITL callbacks mirror RuntimeEventPoller so child human_input.requested
 * and tool_question.requested events can drive the shared QuestionCoordinator.
 */
final class SubagentLiveChildViewPoller
{
    private const float POLL_INTERVAL = 0.05;

    private readonly TuiRuntimeEventApplier $eventApplier;

    /** @var list<RuntimeEvent> */
    private array $pendingEvents = [];

    public function __construct(
        private readonly TranscriptProjectorInterface $projector,
        private readonly LoggerInterface $logger,
        DenormalizerInterface $denormalizer,
        private readonly ?ToolQuestionStoreInterface $toolQuestionStore = null,
    ) {
        $this->eventApplier = new TuiRuntimeEventApplier($this->projector, $denormalizer);
    }

    public function resetProjection(): void
    {
        $this->projector->reset();
        $this->pendingEvents = [];
    }

    /**
     * One-time apply of a child snapshot into live view state and the child projector.
     *
     * Call only while {@see SubagentLiveViewState::$active} is true (picker sets this before apply).
     * Reconstructs presentation from the durable snapshot, redispatches unresolved canonical
     * human_input.requested events, and restores pending local tool questions from the DB store.
     *
     * @param ?callable(RuntimeEvent): void $onHumanInputRequested
     * @param ?callable(RuntimeEvent): void $onToolQuestionRequested
     * @param ?callable(RuntimeEvent): void $onToolTerminal
     *
     * @return list<TranscriptBlock>
     */
    public function replaySnapshot(
        SubagentLiveViewState $live,
        ChildRunTranscriptSnapshotDTO $snapshot,
        ?callable $onHumanInputRequested = null,
        ?callable $onToolQuestionRequested = null,
        ?callable $onToolTerminal = null,
    ): array {
        if (!$live->active || null === $live->selected) {
            return $live->childTranscript;
        }

        $callbacks = $this->makeCallbacks($onHumanInputRequested, $onToolQuestionRequested, $onToolTerminal);

        $this->resetProjection();

        $scratch = new TuiSessionState($live->selected->agentRunId);
        $scratch->activity = $live->childActivity;
        $scratch->queuedUserMessages = $live->childQueuedUserMessages;

        foreach ($snapshot->replayEvents as $event) {
            $this->eventApplier->apply($scratch, $event, replayMode: true);
            if ($this->isUnresolvedHumanInputRequest($event, $snapshot->replayEvents)) {
                $callbacks->dispatch($event, $live->selected->agentRunId);
            }
        }

        foreach ($this->pendingToolQuestionEvents($live->selected->agentRunId) as $event) {
            $callbacks->dispatch($event, $live->selected->agentRunId);
        }

        $live->childActivity = $scratch->activity;
        $live->childQueuedUserMessages = $scratch->queuedUserMessages;
        $live->childLastSeq = $snapshot->maxSeq;
        $projected = $this->projector->blocks();
        $live->childTranscript = [] !== $projected
            ? $projected
            : $snapshot->transcriptBlocks;
        // Snapshot apply establishes the mounted baseline. Discard its full dirty
        // state so later live stream batches produce bounded incremental patches.
        $this->eventApplier->drainProjectedChanges();

        return $live->childTranscript;
    }

    /**
     * @param ?callable(RuntimeEvent): void $onHumanInputRequested
     * @param ?callable(RuntimeEvent): void $onToolQuestionRequested
     * @param ?callable(RuntimeEvent): void $onToolTerminal
     *
     * @return TranscriptChangeSet|null Incremental transcript changes, or null when events changed only non-transcript state
     */
    public function poll(
        SubagentLiveViewState $live,
        AgentSessionClient $client,
        ?callable $onHumanInputRequested = null,
        ?callable $onToolQuestionRequested = null,
        ?callable $onToolTerminal = null,
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
        $previousBlockIds = array_fill_keys(array_map(
            static fn (TranscriptBlock $block): string => $block->id,
            $live->childTranscript,
        ), true);
        $scratch = new TuiSessionState($live->selected->agentRunId);
        $scratch->activity = $live->childActivity;
        $scratch->queuedUserMessages = $live->childQueuedUserMessages;

        $callbacks = $this->makeCallbacks($onHumanInputRequested, $onToolQuestionRequested, $onToolTerminal);

        foreach ($events as $index => $event) {
            $seq = $event->seq;
            if (0 !== $seq && $seq <= $live->childLastSeq) {
                continue;
            }

            try {
                $this->eventApplier->apply($scratch, $event);
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
        }

        if (!$changed) {
            return null;
        }

        $live->childTranscript = $this->projector->blocks();

        $transcriptChanges = $this->eventApplier->drainProjectedChanges();
        // Entry placeholders and snapshot fallbacks are mounted from childTranscript,
        // not the projector. Carry their disappearance as explicit removals so the
        // incremental screen state converges with the projector-backed view.
        $currentBlockIds = array_fill_keys(array_map(
            static fn (TranscriptBlock $block): string => $block->id,
            $live->childTranscript,
        ), true);
        $removedVisibleIds = array_keys(array_diff_key($previousBlockIds, $currentBlockIds));
        if ([] !== $removedVisibleIds) {
            $transcriptChanges = TranscriptChangeSet::incremental(
                $transcriptChanges->upserts,
                array_values(array_unique([...$transcriptChanges->removals, ...$removedVisibleIds])),
                $transcriptChanges->retentionFloorBlockId,
            );
        }

        return $transcriptChanges->isEmpty() ? null : $transcriptChanges;
    }

    /**
     * @param ?callable(RuntimeEvent): void $onHumanInputRequested
     * @param ?callable(RuntimeEvent): void $onToolQuestionRequested
     * @param ?callable(RuntimeEvent): void $onToolTerminal
     */
    private function makeCallbacks(
        ?callable $onHumanInputRequested,
        ?callable $onToolQuestionRequested,
        ?callable $onToolTerminal,
    ): RuntimeEventCallbacks {
        return new RuntimeEventCallbacks(
            $this->logger,
            'SubagentLiveChildViewPoller event callback failed',
            'tui.subagent_live_child_poller',
            'subagent_live_child_poller.callback_failed',
            $onHumanInputRequested,
            $onToolQuestionRequested,
            $onToolTerminal,
        );
    }

    /**
     * @param list<RuntimeEvent> $events
     */
    private function isUnresolvedHumanInputRequest(RuntimeEvent $event, array $events): bool
    {
        if (RuntimeEventTypeEnum::HumanInputRequested->value !== $event->type) {
            return false;
        }

        $questionId = (string) ($event->payload['question_id'] ?? '');
        if ('' === $questionId) {
            return false;
        }

        foreach ($events as $candidate) {
            if ($candidate->runId !== $event->runId) {
                continue;
            }

            if (
                RuntimeEventTypeEnum::HumanInputAnswered->value !== $candidate->type
                && RuntimeEventTypeEnum::HumanInputRejected->value !== $candidate->type
            ) {
                continue;
            }

            if ((string) ($candidate->payload['question_id'] ?? '') === $questionId) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<RuntimeEvent>
     */
    private function pendingToolQuestionEvents(string $runId): array
    {
        if (null === $this->toolQuestionStore) {
            return [];
        }

        $events = [];
        foreach ($this->toolQuestionStore->findPendingQuestionsForRun($runId) as $question) {
            $schema = $question->schema;
            if (\is_string($schema) && '' !== $schema) {
                try {
                    $decoded = json_decode($schema, true, 512, \JSON_THROW_ON_ERROR);
                    $schema = \is_array($decoded) ? $decoded : ['type' => 'boolean'];
                } catch (\JsonException) {
                    $schema = ['type' => 'boolean'];
                }
            } else {
                $schema = ['type' => 'boolean'];
            }

            $events[] = new RuntimeEvent(
                type: RuntimeEventTypeEnum::ToolQuestionRequested->value,
                runId: $question->runId,
                seq: 0,
                payload: [
                    'request_id' => $question->requestId,
                    'run_id' => $question->runId,
                    'tool_call_id' => $question->toolCallId,
                    'tool_name' => $question->toolName,
                    'pid' => $question->pid,
                    'log_path' => $question->logPath,
                    'command_preview' => $question->commandPreview,
                    'prompt' => $question->prompt,
                    'kind' => $question->kind,
                    'schema' => $schema,
                    'transcript' => false,
                ],
            );
        }

        return $events;
    }
}
