<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Application\Handler\RunStateReplayException;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\TurnTree\BranchReplayFilterInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;

final readonly class SessionRunStateReplayService implements RunStateRebuilderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private LoggerInterface $logger,
        private RunStateReducer $runStateReducer,
        private ReplayEventPreparer $replayEventPreparer,
        private ?BranchReplayFilterInterface $turnTreeReplayFilter = null,
    ) {
    }

    public function rebuildIfStale(RunState $state, string $runId): RunStateReplayResult
    {
        $events = $this->eventStore->allFor($runId);

        if ([] === $events) {
            return RunStateReplayResult::noEvents();
        }

        $sortedEvents = $this->replayEventPreparer->sortBySequence($events);
        $maxEventSeq = $this->replayEventPreparer->maxSequence($sortedEvents);

        // Stored state is current — no rebuild needed.
        if ($state->lastSeq >= $maxEventSeq) {
            return RunStateReplayResult::current($maxEventSeq, \count($sortedEvents));
        }

        RunLogContext::enter(['run_id' => $runId, 'component' => 'replay']);

        try {
            // Detect duplicates before contiguity check so the diagnostic
            // reports the right failure reason and replay never processes
            // duplicate sequences.
            $duplicateSeqs = $this->replayEventPreparer->duplicateSequences($sortedEvents);
            if ([] !== $duplicateSeqs) {
                $this->logger->error('run_state_replay.duplicate_sequences', [
                    'run_id' => $runId,
                    'event_count' => \count($sortedEvents),
                    'duplicate_sequences' => $duplicateSeqs,
                    'duplicate_count' => \count($duplicateSeqs),
                ]);

                throw RunStateReplayException::duplicateSequences(\sprintf('Cannot replay run %s: event history contains %d duplicate sequence number(s): %s.', $runId, \count($duplicateSeqs), implode(', ', array_map('strval', \array_slice($duplicateSeqs, 0, 10)))));
            }

            $missingSequences = $this->replayEventPreparer->missingSequences($sortedEvents);
            $isContiguous = [] === $missingSequences;

            $this->logger->info('run_state_replay.rebuilding', [
                'run_id' => $runId,
                'state_last_seq' => $state->lastSeq,
                'event_last_seq' => $maxEventSeq,
                'event_count' => \count($sortedEvents),
                'is_contiguous' => $isContiguous,
                'missing_sequences_count' => \count($missingSequences),
            ]);

            if (!$isContiguous) {
                $this->logger->info('run_state_replay.gap_sequences_allowed', [
                    'run_id' => $runId,
                    'state_last_seq' => $state->lastSeq,
                    'event_last_seq' => $maxEventSeq,
                    'event_count' => \count($sortedEvents),
                    'missing_sequences' => $missingSequences,
                ]);
            }

            // Filter to active branch events when tree metadata is available.
            // Abandoned sibling branch events (message/tool/assistant content for
            // turns not on the active path) are excluded from replay while the
            // canonical stream integrity checks remain on the full sorted stream.
            $filteredEvents = $sortedEvents;
            if (null !== $this->turnTreeReplayFilter) {
                $branchReplay = $this->turnTreeReplayFilter->filter($runId, $sortedEvents);
                $filteredEvents = $branchReplay->events;

                $this->logger->info('run_state_replay.branch_filtered', [
                    'run_id' => $runId,
                    'canonical_event_count' => $branchReplay->canonicalEventCount,
                    'filtered_event_count' => \count($filteredEvents),
                    'current_leaf_turn_no' => $branchReplay->currentLeafTurnNo,
                    'active_branch_turns' => $branchReplay->activePathTurnNos,
                ]);
            }

            $rebuiltState = $this->runStateReducer->replay($state, $filteredEvents);

            // After replay, ensure lastSeq reflects the full canonical stream
            // so state is current with respect to the append-only event log,
            // even when replaying an earlier branch/leaf.
            $rebuiltState = new RunState(
                runId: $rebuiltState->runId,
                status: $rebuiltState->status,
                version: $rebuiltState->version,
                turnNo: $rebuiltState->turnNo,
                lastSeq: $maxEventSeq,
                isStreaming: $rebuiltState->isStreaming,
                streamingMessage: $rebuiltState->streamingMessage,
                pendingToolCalls: $rebuiltState->pendingToolCalls,
                errorMessage: $rebuiltState->errorMessage,
                messages: $rebuiltState->messages,
                activeStepId: $rebuiltState->activeStepId,
                retryableFailure: $rebuiltState->retryableFailure,
                retryAttempts: $rebuiltState->retryAttempts,
            );

            $this->logger->info('run_state_replay.rebuilt', [
                'run_id' => $runId,
                'replayed_seq_count' => \count($sortedEvents),
                'rebuilt_message_count' => \count($rebuiltState->messages),
                'rebuilt_status' => $rebuiltState->status->value,
                'rebuilt_turn_no' => $rebuiltState->turnNo,
            ]);

            return RunStateReplayResult::rebuilt(
                $rebuiltState,
                $maxEventSeq,
                \count($sortedEvents),
                $isContiguous,
                $missingSequences,
            );
        } finally {
            RunLogContext::leave();
        }
    }

    public function rebuildForLeaf(RunState $state, string $runId, int $targetLeafTurnNo): RunStateReplayResult
    {
        $events = $this->eventStore->allFor($runId);

        if ([] === $events) {
            return RunStateReplayResult::noEvents();
        }

        $sortedEvents = $this->replayEventPreparer->sortBySequence($events);
        $maxEventSeq = $this->replayEventPreparer->maxSequence($sortedEvents);

        RunLogContext::enter(['run_id' => $runId, 'component' => 'replay']);

        try {
            // Full-stream integrity checks (duplicates + contiguity) on the
            // canonical stream before branch filtering.
            $duplicateSeqs = $this->replayEventPreparer->duplicateSequences($sortedEvents);
            if ([] !== $duplicateSeqs) {
                $this->logger->error('run_state_replay.duplicate_sequences', [
                    'run_id' => $runId,
                    'event_count' => \count($sortedEvents),
                    'duplicate_sequences' => $duplicateSeqs,
                    'duplicate_count' => \count($duplicateSeqs),
                ]);

                throw RunStateReplayException::duplicateSequences(\sprintf('Cannot replay run %s for leaf %d: event history contains %d duplicate sequence number(s): %s.', $runId, $targetLeafTurnNo, \count($duplicateSeqs), implode(', ', array_map('strval', \array_slice($duplicateSeqs, 0, 10)))));
            }

            $missingSequences = $this->replayEventPreparer->missingSequences($sortedEvents);
            $isContiguous = [] === $missingSequences;

            if (!$isContiguous) {
                $this->logger->info('run_state_replay.gap_sequences_allowed_for_leaf', [
                    'run_id' => $runId,
                    'target_leaf_turn_no' => $targetLeafTurnNo,
                    'event_count' => \count($sortedEvents),
                    'missing_sequences' => $missingSequences,
                ]);
            }

            // Filter to the target leaf's branch path.
            $filteredEvents = $sortedEvents;
            if (null !== $this->turnTreeReplayFilter) {
                $branchReplay = $this->turnTreeReplayFilter->filterForLeaf($runId, $sortedEvents, $targetLeafTurnNo);
                $filteredEvents = $branchReplay->events;

                $this->logger->info('run_state_replay.rebuild_for_leaf_filtered', [
                    'run_id' => $runId,
                    'target_leaf_turn_no' => $targetLeafTurnNo,
                    'canonical_event_count' => $branchReplay->canonicalEventCount,
                    'filtered_event_count' => \count($filteredEvents),
                    'active_branch_turns' => $branchReplay->activePathTurnNos,
                ]);

                // Rewind replay must not resurrect post-completion follow_up commands
                // that were queued on the target turn to launch an abandoned child
                // branch (e.g. pineapple at seq 6–7 after turn-1 agent_end). Those
                // leave status=Running and suppress ApplyCommandHandler's immediate
                // AdvanceRun for the next follow_up on the new branch.
                $filteredEvents = $this->filterAbandonedChildLaunchCommandsOnTargetLeaf(
                    $sortedEvents,
                    $targetLeafTurnNo,
                    $filteredEvents,
                );
            }

            $rebuiltState = $this->runStateReducer->replay($state, $filteredEvents);

            // Overwrite lastSeq to the full canonical stream max so the state
            // is current with respect to the append-only event log.
            $rebuiltState = new RunState(
                runId: $rebuiltState->runId,
                status: $rebuiltState->status,
                version: $rebuiltState->version,
                turnNo: $rebuiltState->turnNo,
                lastSeq: $maxEventSeq,
                isStreaming: $rebuiltState->isStreaming,
                streamingMessage: $rebuiltState->streamingMessage,
                pendingToolCalls: $rebuiltState->pendingToolCalls,
                errorMessage: $rebuiltState->errorMessage,
                messages: $rebuiltState->messages,
                activeStepId: $rebuiltState->activeStepId,
                retryableFailure: $rebuiltState->retryableFailure,
                retryAttempts: $rebuiltState->retryAttempts,
            );

            $this->logger->info('run_state_replay.rebuilt_for_leaf', [
                'run_id' => $runId,
                'target_leaf_turn_no' => $targetLeafTurnNo,
                'rebuilt_message_count' => \count($rebuiltState->messages),
                'rebuilt_status' => $rebuiltState->status->value,
                'rebuilt_turn_no' => $rebuiltState->turnNo,
            ]);

            return RunStateReplayResult::rebuilt(
                $rebuiltState,
                $maxEventSeq,
                \count($sortedEvents),
                $isContiguous,
                $missingSequences,
            );
        } finally {
            RunLogContext::leave();
        }
    }

    /**
     * @param list<RunEvent> $sortedEvents
     * @param list<RunEvent> $filteredEvents
     *
     * @return list<RunEvent>
     */
    private function filterAbandonedChildLaunchCommandsOnTargetLeaf(
        array $sortedEvents,
        int $targetLeafTurnNo,
        array $filteredEvents,
    ): array {
        // Only relevant when rebuilding immediately after a rewind leaf_set
        // (RunRewindService::rewind → rebuildForLeaf). Strip follow_up commands
        // that were queued on the target turn after its last completion but
        // before the rewind leaf_set — those launched an abandoned child branch.
        $rewindLeafSetSeq = 0;
        foreach ($sortedEvents as $event) {
            if (RunEventTypeEnum::LeafSet->value !== $event->type) {
                continue;
            }

            $payload = $event->payload;
            $leafTurnNo = (int) ($payload['turn_no'] ?? 0);
            $reason = \is_string($payload['reason'] ?? null) ? $payload['reason'] : '';

            if ($leafTurnNo !== $targetLeafTurnNo || 'rewind' !== $reason) {
                continue;
            }

            $rewindLeafSetSeq = max($rewindLeafSetSeq, $event->seq);
        }

        if (0 === $rewindLeafSetSeq) {
            return $filteredEvents;
        }

        $turnCompletionSeq = 0;
        foreach ($sortedEvents as $event) {
            if ($event->turnNo !== $targetLeafTurnNo || $event->seq >= $rewindLeafSetSeq) {
                continue;
            }

            if (\in_array($event->type, [
                RunEventTypeEnum::AgentEnd->value,
                RunEventTypeEnum::LlmStepCompleted->value,
            ], true)) {
                $turnCompletionSeq = max($turnCompletionSeq, $event->seq);
            }
        }

        if (0 === $turnCompletionSeq) {
            return $filteredEvents;
        }

        return array_values(array_filter(
            $filteredEvents,
            static function (RunEvent $event) use ($targetLeafTurnNo, $turnCompletionSeq, $rewindLeafSetSeq): bool {
                if ($event->turnNo !== $targetLeafTurnNo) {
                    return true;
                }

                if (!\in_array($event->type, [
                    RunEventTypeEnum::AgentCommandQueued->value,
                    RunEventTypeEnum::AgentCommandApplied->value,
                ], true)) {
                    return true;
                }

                if ($event->seq <= $turnCompletionSeq) {
                    return true;
                }

                return $event->seq >= $rewindLeafSetSeq;
            },
        ));
    }
}
