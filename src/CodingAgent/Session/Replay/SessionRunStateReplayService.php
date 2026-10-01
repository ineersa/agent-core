<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\CodingAgent\Session\History\HistoryProjectionStoreInterface;
use Ineersa\CodingAgent\Session\RunState\RunStateStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Explicit startup/recovery RunState rebuild.
 *
 * Ordinary shared lookups never call this. Rebuilds go through one cold
 * reconstruction pass that also publishes shared history/state projections.
 * When ready shared projections already match the caller's cursor, rebuild
 * reuses them without an archive tip probe.
 */
final readonly class SessionRunStateReplayService implements RunStateRebuilderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private LoggerInterface $logger,
        private SessionColdReconstructionService $coldReconstruction,
        private HistoryProjectionStoreInterface $historyProjectionStore,
        private RunStateStoreInterface $runStateStore,
        private RunLockManager $runLockManager,
    ) {
    }

    public function rebuildIfStale(RunState $state, string $runId): RunStateReplayResult
    {
        $ready = $this->tryReadyShared($runId);
        if (null !== $ready) {
            if ($ready->lastSeq === $state->lastSeq) {
                if ($ready->turnNo === $state->turnNo) {
                    return RunStateReplayResult::current();
                }

                // Same committed sequence with a stale caller turn still reuses the
                // ready shared projection. Do not tip-probe the archive.
                return RunStateReplayResult::rebuilt($ready);
            }
            if ($ready->lastSeq > $state->lastSeq) {
                return RunStateReplayResult::rebuilt($ready);
            }
        }

        $maxEventSeq = $this->eventStore->latestSequenceFor($runId);
        if (null === $maxEventSeq) {
            $this->historyProjectionStore->initializeFromEvents($runId, []);
            $this->runStateStore->initialize(RunState::queued($runId));

            return RunStateReplayResult::noEvents();
        }

        if ($state->lastSeq >= $maxEventSeq) {
            // Caller cursor is current, but missing/withdrawn shared projections still
            // require explicit recovery publication. Do not leave the cache empty.
            if (null === $ready) {
                $this->logger->info('run_state_replay.republishing_current_projections', [
                    'run_id' => $runId,
                    'state_last_seq' => $state->lastSeq,
                    'event_last_seq' => $maxEventSeq,
                ]);

                $result = $this->coldReconstruction->reconstruct(
                    runId: $runId,
                    seedState: $state,
                    publishSharedState: true,
                    publishHistory: true,
                    knownMaxSeq: $maxEventSeq,
                );

                return RunStateReplayResult::rebuilt($result->runState);
            }

            return RunStateReplayResult::current();
        }

        RunLogContext::enter(['run_id' => $runId, 'component' => 'replay']);

        try {
            $this->logger->info('run_state_replay.rebuilding', [
                'run_id' => $runId,
                'state_last_seq' => $state->lastSeq,
                'event_last_seq' => $maxEventSeq,
            ]);

            $result = $this->coldReconstruction->reconstruct(
                runId: $runId,
                seedState: $state,
                publishSharedState: true,
                publishHistory: true,
                knownMaxSeq: $maxEventSeq,
            );

            $this->logger->info('run_state_replay.rebuilt', [
                'run_id' => $runId,
                'rebuilt_message_count' => \count($result->runState->messages),
                'rebuilt_status' => $result->runState->status->value,
                'rebuilt_turn_no' => $result->runState->turnNo,
            ]);

            return RunStateReplayResult::rebuilt($result->runState);
        } finally {
            RunLogContext::leave();
        }
    }

    public function rebuildAtPosition(RunState $state, string $runId, int $positionTurnNo): RunStateReplayResult
    {
        $maxEventSeq = $this->eventStore->latestSequenceFor($runId);
        if (null === $maxEventSeq) {
            return RunStateReplayResult::noEvents();
        }

        RunLogContext::enter(['run_id' => $runId, 'component' => 'replay']);

        try {
            $result = $this->coldReconstruction->reconstruct(
                runId: $runId,
                positionTurnNo: $positionTurnNo,
                seedState: $state,
                publishSharedState: true,
                publishHistory: true,
                knownMaxSeq: $maxEventSeq,
            );

            $this->logger->info('run_state_replay.rebuilt_at_position', [
                'run_id' => $runId,
                'position_turn_no' => $positionTurnNo,
                'rebuilt_message_count' => \count($result->runState->messages),
                'rebuilt_status' => $result->runState->status->value,
                'rebuilt_turn_no' => $result->runState->turnNo,
            ]);

            return RunStateReplayResult::rebuilt($result->runState);
        } finally {
            RunLogContext::leave();
        }
    }

    private function tryReadyShared(string $runId): ?RunState
    {
        return $this->runLockManager->synchronized($runId, function () use ($runId): ?RunState {
            try {
                if (!$this->runStateStore->isReady($runId)) {
                    return null;
                }

                $state = $this->runStateStore->get($runId);
                $history = $this->historyProjectionStore->get($runId);
                if ($history->lastSeq !== $state->lastSeq || $history->history->positionTurnNo !== $state->turnNo) {
                    $this->logger->warning('run_state_replay.ready_projection_mismatch', [
                        'run_id' => $runId,
                        'component' => 'replay',
                        'state_last_seq' => $state->lastSeq,
                        'history_last_seq' => $history->lastSeq,
                        'state_turn_no' => $state->turnNo,
                        'history_position_turn_no' => $history->history->positionTurnNo,
                    ]);

                    return null;
                }

                return $state;
            } catch (\RuntimeException $exception) {
                // Explicit recovery may reconstruct after a logged degradation.
                // Do not hide the cause as a silent miss.
                $this->logger->warning('run_state_replay.ready_projection_unavailable', [
                    'run_id' => $runId,
                    'component' => 'replay',
                    'exception_class' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                return null;
            }
        });
    }
}
