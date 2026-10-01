<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\History\HistoryProjectionStoreInterface;
use Ineersa\CodingAgent\Session\RunState\RunStateStoreInterface;

/**
 * Shared current RunState context.
 *
 * Each lookup checks the shared projection under the transition lock. Local
 * sequence markers detect regression without retaining another message graph.
 * Missing/expired shared state requires explicit startup/new-run/recovery.
 */
final class ActiveRunContext implements ActiveRunContextInterface
{
    /** @var array<string, int> */
    private array $lastSequences = [];

    public function __construct(
        private readonly RunStateStoreInterface $runStateStore,
        private readonly RunOperationalProjectionRepository $projectionRepository,
        private readonly RunLockManager $runLockManager,
        private readonly HistoryProjectionStoreInterface $historyProjectionStore,
    ) {
    }

    public function stateFor(string $runId): RunState
    {
        return $this->runLockManager->synchronized($runId, function () use ($runId): RunState {
            $state = $this->runStateStore->get($runId);
            if ($state->lastSeq < ($this->lastSequences[$runId] ?? 0)) {
                throw new \RuntimeException(\sprintf('Shared run state regressed for run %s; recovery required.', $runId));
            }
            $history = $this->historyProjectionStore->get($runId);
            if ($history->lastSeq !== $state->lastSeq) {
                throw new \RuntimeException(\sprintf('Run state/history cursor mismatch for run %s; recovery required.', $runId));
            }
            if ($history->history->positionTurnNo !== $state->turnNo) {
                throw new \RuntimeException(\sprintf('Run state/history turn mismatch for run %s (state turn %d, history position %d); recovery required.', $runId, $state->turnNo, $history->history->positionTurnNo));
            }
            $this->lastSequences[$runId] = $state->lastSeq;

            return $state;
        });
    }

    public function remember(RunState $state): void
    {
        $this->runLockManager->synchronized($state->runId, function () use ($state): void {
            $cached = $this->runStateStore->find($state->runId);
            if (null !== $cached && $cached->lastSeq > $state->lastSeq) {
                throw new \RuntimeException(\sprintf('Cannot regress shared run state for run %s.', $state->runId));
            }
            try {
                $this->persistOperational($state);
                $this->runStateStore->remember($state);
            } catch (\Throwable $e) {
                unset($this->lastSequences[$state->runId]);
                $this->runStateStore->invalidate($state->runId);

                throw $e;
            }
            $this->lastSequences[$state->runId] = $state->lastSeq;
        });
    }

    /**
     * Publish an empty queued projection for a new run before the first handler.
     * Idempotent when the shared projection already matches queued/lastSeq=0.
     */
    public function initializeQueued(string $runId): RunState
    {
        return $this->runLockManager->synchronized($runId, function () use ($runId): RunState {
            if (null !== $this->runStateStore->find($runId)) {
                return $this->stateFor($runId);
            }

            $this->historyProjectionStore->initializeFromEvents($runId, []);
            $queued = RunState::queued($runId);
            $this->initialize($queued);

            return $queued;
        });
    }

    /**
     * Cold recovery/startup publish of an already-reconstructed authoritative state.
     */
    public function initialize(RunState $state): void
    {
        $this->runLockManager->synchronized($state->runId, function () use ($state): void {
            // A conflicting initialization must not invalidate a newer writer.
            $this->runStateStore->initialize($state);
            try {
                $this->persistOperational($state);
            } catch (\Throwable $e) {
                $this->runStateStore->invalidate($state->runId);

                throw $e;
            }
            $this->lastSequences[$state->runId] = $state->lastSeq;
        });
    }

    /**
     * Apply already-committed side-writer events onto the shared current state.
     *
     * @param list<RunEvent>                               $events
     * @param callable(RunState, list<RunEvent>): RunState $advance
     */
    public function applyCommittedSuffix(string $runId, array $events, callable $advance): RunState
    {
        if ([] === $events) {
            return $this->stateFor($runId);
        }

        // Withdraw-before-append leaves projections not-ready. Use the retained
        // pre-commit state for the protected advance, then republish ready.
        $current = $this->runStateStore->find($runId)
            ?? throw new \RuntimeException(\sprintf('Run state projection missing for run %s; initialize via startup/new-run/recovery before ordinary lookups.', $runId));
        $maxSeq = 0;
        foreach ($events as $event) {
            if ($event->runId !== $runId) {
                throw new \RuntimeException(\sprintf('Cannot apply committed run-state suffix for run %s: event belongs to another run.', $runId));
            }
            $maxSeq = max($maxSeq, $event->seq);
        }

        if ($current->lastSeq >= $maxSeq) {
            $this->remember($current);

            return $this->stateFor($runId);
        }

        $next = $advance($current, $events);
        if ($next->runId !== $runId) {
            throw new \RuntimeException(\sprintf('Committed run-state suffix advance changed run id for %s.', $runId));
        }
        if ($next->lastSeq < $maxSeq) {
            throw new \RuntimeException(\sprintf('Committed run-state suffix advance for run %s stopped at seq %d before committed seq %d.', $runId, $next->lastSeq, $maxSeq));
        }

        $this->remember($next);

        return $next;
    }

    public function invalidate(string $runId): void
    {
        unset($this->lastSequences[$runId]);
    }

    public function withdrawForCommit(string $runId): void
    {
        $this->runLockManager->synchronized($runId, function () use ($runId): void {
            unset($this->lastSequences[$runId]);
            $this->runStateStore->withdrawForCommit($runId);
            $this->historyProjectionStore->withdrawForCommit($runId);
        });
    }

    public function clear(): void
    {
        $this->lastSequences = [];
    }

    private function persistOperational(RunState $state): void
    {
        $this->projectionRepository->replace($state);
    }
}
