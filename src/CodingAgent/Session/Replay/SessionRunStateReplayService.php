<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Application\Handler\RunStateDuplicateSequenceReplayException;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\CodingAgent\Session\History\HistoryReplayFilter;
use Psr\Log\LoggerInterface;

final readonly class SessionRunStateReplayService implements RunStateRebuilderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private LoggerInterface $logger,
        private RunStateReducer $runStateReducer,
        private ReplayEventPreparer $replayEventPreparer,
        private HistoryReplayFilter $historyReplayFilter,
    ) {
    }

    public function rebuildIfStale(RunState $state, string $runId): RunStateReplayResult
    {
        $maxEventSeq = $this->eventStore->latestSequenceFor($runId);
        if (null === $maxEventSeq) {
            return RunStateReplayResult::noEvents();
        }

        // Stored state is current — avoid decoding the full canonical history.
        if ($state->lastSeq >= $maxEventSeq) {
            return RunStateReplayResult::current($maxEventSeq, 0);
        }

        $events = $this->eventStore->allFor($runId);
        $sortedEvents = $this->replayEventPreparer->sortBySequence($events);

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

                throw new RunStateDuplicateSequenceReplayException(\sprintf('Cannot replay run %s: event history contains %d duplicate sequence number(s): %s.', $runId, \count($duplicateSeqs), implode(', ', array_map('strval', \array_slice($duplicateSeqs, 0, 10)))));
            }

            $this->logger->info('run_state_replay.rebuilding', [
                'run_id' => $runId,
                'state_last_seq' => $state->lastSeq,
                'event_last_seq' => $maxEventSeq,
                'event_count' => \count($sortedEvents),
            ]);

            // Always filter to retained history. Discarded-turn content is
            // excluded while canonical integrity checks remain on the full stream.
            // HistoryReplayFilter also strips unmatched post-completion launches
            // so crash recovery (rebuildIfStale) matches rebuildAtPosition.
            $filteredEvents = $this->historyReplayFilter->filter($sortedEvents);

            $this->logger->info('run_state_replay.history_filtered', [
                'run_id' => $runId,
                'canonical_event_count' => \count($sortedEvents),
                'filtered_event_count' => \count($filteredEvents),
            ]);

            $rebuiltState = $this->runStateReducer->replay($state, $filteredEvents);

            // After replay, ensure lastSeq reflects the full canonical stream
            // so state is current with respect to the append-only event log,
            // even when replaying an earlier history position.
            $rebuiltState = $rebuiltState->with(['lastSeq' => $maxEventSeq]);

            $this->logger->info('run_state_replay.rebuilt', [
                'run_id' => $runId,
                'replayed_seq_count' => \count($sortedEvents),
                'rebuilt_message_count' => \count($rebuiltState->messages),
                'rebuilt_status' => $rebuiltState->status->value,
                'rebuilt_turn_no' => $rebuiltState->turnNo,
            ]);

            // Contiguity fields on RunStateReplayResult are legacy defaults; gaps in seq are valid
            // (cursor may advance before JSONL append). rebuilt=true means state was refreshed from events.
            return RunStateReplayResult::rebuilt(
                $rebuiltState,
                $maxEventSeq,
                \count($sortedEvents),
                true,
            );
        } finally {
            RunLogContext::leave();
        }
    }

    public function rebuildAtPosition(RunState $state, string $runId, int $positionTurnNo): RunStateReplayResult
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
            // canonical stream before retained-history filtering.
            $duplicateSeqs = $this->replayEventPreparer->duplicateSequences($sortedEvents);
            if ([] !== $duplicateSeqs) {
                $this->logger->error('run_state_replay.duplicate_sequences', [
                    'run_id' => $runId,
                    'event_count' => \count($sortedEvents),
                    'duplicate_sequences' => $duplicateSeqs,
                    'duplicate_count' => \count($duplicateSeqs),
                ]);

                throw new RunStateDuplicateSequenceReplayException(\sprintf('Cannot replay run %s at position %d: event history contains %d duplicate sequence number(s): %s.', $runId, $positionTurnNo, \count($duplicateSeqs), implode(', ', array_map('strval', \array_slice($duplicateSeqs, 0, 10)))));
            }

            // Retained prefix at the selected position (0 = before first turn).
            // Unmatched pending-command suppression for history_select recovery is
            // owned by HistoryReplayFilter for both rebuildAtPosition and rebuildIfStale.
            $filteredEvents = $this->historyReplayFilter->filterAtPosition($sortedEvents, $positionTurnNo);

            $this->logger->info('run_state_replay.rebuild_at_position_filtered', [
                'run_id' => $runId,
                'position_turn_no' => $positionTurnNo,
                'canonical_event_count' => \count($sortedEvents),
                'filtered_event_count' => \count($filteredEvents),
            ]);

            $rebuiltState = $this->runStateReducer->replay($state, $filteredEvents);

            // Force turnNo to the requested position (boundary 0 stays 0) and lastSeq
            // to the full canonical stream max so the state is current with the log.
            $rebuiltState = $rebuiltState->with([
                'turnNo' => $positionTurnNo,
                'lastSeq' => $maxEventSeq,
            ]);

            $this->logger->info('run_state_replay.rebuilt_at_position', [
                'run_id' => $runId,
                'position_turn_no' => $positionTurnNo,
                'rebuilt_message_count' => \count($rebuiltState->messages),
                'rebuilt_status' => $rebuiltState->status->value,
                'rebuilt_turn_no' => $rebuiltState->turnNo,
            ]);

            return RunStateReplayResult::rebuilt(
                $rebuiltState,
                $maxEventSeq,
                \count($sortedEvents),
                true,
            );
        } finally {
            RunLogContext::leave();
        }
    }
}
