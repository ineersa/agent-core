<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\HotPromptStateRebuilderInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Psr\Log\LoggerInterface;

final readonly class RunCommit
{
    public function __construct(
        private RunStoreInterface $runStore,
        private EventStoreInterface $eventStore,
        private CommandStoreInterface $commandStore,
        private HotPromptStateRebuilderInterface $hotPromptStateRebuilder,
        private StepDispatcher $stepDispatcher,
        private LoggerInterface $logger,
        private ?HookDispatcher $hookDispatcher = null,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
    ) {
    }

    /**
     * @param list<RunEvent> $events
     * @param list<object>   $effects
     */
    public function commit(RunState $state, RunState $nextState, array $events, array $effects = []): bool
    {
        $resolvedNextState = $nextState;

        $persist = function () use ($state, &$resolvedNextState, $events, $effects): ?RunState {
            if (!$this->runStore->compareAndSwap($resolvedNextState, $state->version)) {
                return null;
            }

            $eventsPersisted = false;
            /** @var list<RunEvent> $persistedEvents */
            $persistedEvents = [];

            try {
                if ([] !== $events) {
                    $persisted = 1 === \count($events)
                        ? [$this->eventStore->append($events[0])]
                        : $this->eventStore->appendMany($events);
                    $persistedEvents = $persisted;
                    $lastPersisted = $persisted[array_key_last($persisted)];
                    if ($resolvedNextState->lastSeq !== $lastPersisted->seq) {
                        $bumpedState = new RunState(
                            runId: $resolvedNextState->runId,
                            status: $resolvedNextState->status,
                            version: $resolvedNextState->version + 1,
                            turnNo: $resolvedNextState->turnNo,
                            lastSeq: $lastPersisted->seq,
                            isStreaming: $resolvedNextState->isStreaming,
                            streamingMessage: $resolvedNextState->streamingMessage,
                            pendingToolCalls: $resolvedNextState->pendingToolCalls,
                            errorMessage: $resolvedNextState->errorMessage,
                            messages: $resolvedNextState->messages,
                            activeStepId: $resolvedNextState->activeStepId,
                            retryableFailure: $resolvedNextState->retryableFailure,
                            pendingHumanInputRequests: $resolvedNextState->pendingHumanInputRequests,
                        );
                        if (!$this->runStore->compareAndSwap($bumpedState, $resolvedNextState->version)) {
                            $this->logger->warning('persistence.last_seq_cas_conflict', [
                                'run_id' => $resolvedNextState->runId,
                                'session_id' => $resolvedNextState->runId,
                                'component' => 'persistence.run_commit',
                                'event_type' => 'persistence.last_seq_cas_conflict',
                                'expected_version' => $resolvedNextState->version,
                                'intended_last_seq' => $lastPersisted->seq,
                                'events_persisted' => true,
                            ]);
                            $actual = $this->runStore->get($resolvedNextState->runId);
                            if (null !== $actual) {
                                $resolvedNextState = $actual;
                            }
                        } else {
                            $resolvedNextState = $bumpedState;
                        }
                    }

                    $eventsPersisted = true;
                }
            } catch (\Throwable $exception) {
                $rollbackRestored = null;
                $rollbackError = null;

                try {
                    $rollbackRestored = $this->runStore->compareAndSwap($state, $resolvedNextState->version);
                } catch (\Throwable $rollbackException) {
                    $rollbackError = $rollbackException->getMessage();
                    $this->logger->warning('Rollback CAS failed after event persistence failure', [
                        'run_id' => $resolvedNextState->runId,
                        'turn_no' => $resolvedNextState->turnNo,
                        'exception' => $rollbackException,
                    ]);
                }

                // Mark the run as failed so the TUI shows a terminal error.
                // Use the original $state values because the rollback
                // already restored it (or failed — in either case $state
                // is our best reference).
                try {
                    $failedState = new RunState(
                        runId: $state->runId,
                        status: RunStatus::Failed,
                        version: $state->version + 1,
                        turnNo: $state->turnNo,
                        lastSeq: $state->lastSeq,
                        isStreaming: $state->isStreaming,
                        streamingMessage: $state->streamingMessage,
                        pendingToolCalls: $state->pendingToolCalls,
                        errorMessage: 'Event persistence failed: '.$exception->getMessage(),
                        messages: $state->messages,
                        activeStepId: $state->activeStepId,
                        retryableFailure: false,
                        pendingHumanInputRequests: $state->pendingHumanInputRequests,
                    );
                    $this->runStore->compareAndSwap($failedState, $state->version);
                } catch (\Throwable $markFailedException) {
                    // Best effort — cannot mark failed in store.
                    $this->logger->warning('Could not mark run as failed after event persistence failure', [
                        'run_id' => $resolvedNextState->runId,
                        'exception' => $markFailedException,
                    ]);
                }

                // Throw a terminal exception so the message processor
                // does NOT retry (this is not a CAS conflict). The
                // app-layer exception boundary decides capture vs crash.
                throw new \RuntimeException(\sprintf('Event persistence failed for run %s turn %d: %s', $resolvedNextState->runId, $resolvedNextState->turnNo, $exception->getMessage()), previous: $exception);
            }

            if ($eventsPersisted) {
                try {
                    $this->hotPromptStateRebuilder->rebuildHotPromptState($resolvedNextState->runId);
                } catch (\Throwable $exception) {
                    // Hot prompt rebuild is best-effort — the previous
                    // hot state is still valid. Log the failure so
                    // operators can diagnose persistent rebuild issues.
                    $this->logger->warning('Hot prompt state rebuild failed (best-effort)', [
                        'run_id' => $resolvedNextState->runId,
                        'turn_no' => $resolvedNextState->turnNo,
                        'step_id' => $resolvedNextState->activeStepId,
                        'exception' => $exception,
                    ]);
                }
            }

            $this->logCommittedEvents($resolvedNextState, $persistedEvents);

            if ([] !== $effects) {
                try {
                    $this->stepDispatcher->dispatchEffects($effects);
                } catch (\Throwable $exception) {
                    // Effect dispatch is best-effort side work.
                    // The primary commit (state + events) succeeded.
                    $this->logger->warning('Effect dispatch failed after successful commit (best-effort)', [
                        'run_id' => $resolvedNextState->runId,
                        'turn_no' => $resolvedNextState->turnNo,
                        'step_id' => $resolvedNextState->activeStepId,
                        'effects_count' => \count($effects),
                        'exception' => $exception,
                    ]);
                }
            }

            try {
                $this->hookDispatcher?->dispatchAfterTurnCommit(
                    AfterTurnCommitHookContext::fromRunState($resolvedNextState, $persistedEvents, \count($effects)),
                );
            } catch (\Throwable $exception) {
                // After-turn commit hooks are optional extension points.
                // A failing hook must not roll back the commit.
                $this->logger->warning('After-turn commit hook failed (best-effort)', [
                    'run_id' => $resolvedNextState->runId,
                    'turn_no' => $resolvedNextState->turnNo,
                    'step_id' => $resolvedNextState->activeStepId,
                    'exception' => $exception,
                ]);
            }

            return $resolvedNextState;
        };

        $resolvedAfterPersist = null === $this->tracer
            ? $persist()
            : $this->tracer->inSpan('persistence.commit', [
                'run_id' => $resolvedNextState->runId,
                'turn_no' => $resolvedNextState->turnNo,
                'step_id' => $resolvedNextState->activeStepId,
                'event_count' => \count($events),
                'effects_count' => \count($effects),
            ], $persist)
        ;

        if (null === $resolvedAfterPersist) {
            return false;
        }

        $this->trackCommitMetrics($state, $resolvedAfterPersist, $events);

        return true;
    }

    /**
     * @param list<RunEvent> $events
     */
    private function trackCommitMetrics(RunState $state, RunState $nextState, array $events): void
    {
        if (null === $this->metrics) {
            return;
        }

        $this->metrics->recordRunStatusTransition($state->status, $nextState->status);
        $this->metrics->setCommandQueueLag($nextState->runId, $this->commandStore->countPending($nextState->runId));

        $staleIgnored = 0;

        foreach ($events as $event) {
            if ('stale_result_ignored' !== $event->type) {
                continue;
            }

            ++$staleIgnored;
        }

        if ($staleIgnored > 0) {
            $this->metrics->incrementStaleResultCount($staleIgnored);
        }
    }

    /**
     * @param list<RunEvent> $events
     */
    private function logCommittedEvents(RunState $state, array $events): void
    {
        if ([] === $events) {
            return;
        }

        $eventsByType = [];
        foreach ($events as $event) {
            $eventsByType[$event->type] = ($eventsByType[$event->type] ?? 0) + 1;
        }

        // Log a summary event for log correlation at the commit level.
        $this->logger->info('persistence.events_committed', [
            'run_id' => $state->runId,
            'turn_no' => $state->turnNo,
            'event_count' => \count($events),
            'events_by_type' => $eventsByType,
            'new_status' => $state->status->value,
            'component' => 'storage',
        ]);

        // Per-event append logs were removed: they duplicated persistence.events_committed
        // at INFO and multiplied log volume (one line per canonical event per commit).
    }
}
