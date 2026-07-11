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
use Ineersa\AgentCore\Contract\SequencedEventStoreInterface;
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
        $persist = function () use ($state, $nextState, $events, $effects): bool {
            if (!$this->runStore->compareAndSwap($nextState, $state->version)) {
                return false;
            }

            $eventsPersisted = false;

            try {
                if ([] !== $events) {
                    if (!$this->eventStore instanceof SequencedEventStoreInterface) {
                        throw new \LogicException('RunCommit requires SequencedEventStoreInterface for canonical event persistence.');
                    }

                    $persisted = 1 === \count($events)
                        ? [$this->eventStore->appendWithNextSeq($events[0])]
                        : $this->eventStore->appendManyWithNextSeq($events);
                    $lastPersisted = $persisted[array_key_last($persisted)];
                    if ($nextState->lastSeq !== $lastPersisted->seq) {
                        $bumpedState = new RunState(
                            runId: $nextState->runId,
                            status: $nextState->status,
                            version: $nextState->version + 1,
                            turnNo: $nextState->turnNo,
                            lastSeq: $lastPersisted->seq,
                            isStreaming: $nextState->isStreaming,
                            streamingMessage: $nextState->streamingMessage,
                            pendingToolCalls: $nextState->pendingToolCalls,
                            errorMessage: $nextState->errorMessage,
                            messages: $nextState->messages,
                            activeStepId: $nextState->activeStepId,
                            retryableFailure: $nextState->retryableFailure,
                        );
                        $this->runStore->compareAndSwap($bumpedState, $nextState->version);
                        $nextState = $bumpedState;
                    }

                    $eventsPersisted = true;
                }
            } catch (\Throwable $exception) {
                $rollbackRestored = null;
                $rollbackError = null;

                try {
                    $rollbackRestored = $this->runStore->compareAndSwap($state, $nextState->version);
                } catch (\Throwable $rollbackException) {
                    $rollbackError = $rollbackException->getMessage();
                    $this->logger->warning('Rollback CAS failed after event persistence failure', [
                        'run_id' => $nextState->runId,
                        'turn_no' => $nextState->turnNo,
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
                    );
                    $this->runStore->compareAndSwap($failedState, $state->version);
                } catch (\Throwable $markFailedException) {
                    // Best effort — cannot mark failed in store.
                    $this->logger->warning('Could not mark run as failed after event persistence failure', [
                        'run_id' => $nextState->runId,
                        'exception' => $markFailedException,
                    ]);
                }

                // Throw a terminal exception so the message processor
                // does NOT retry (this is not a CAS conflict). The
                // app-layer exception boundary decides capture vs crash.
                throw new \RuntimeException(\sprintf('Event persistence failed for run %s turn %d: %s', $nextState->runId, $nextState->turnNo, $exception->getMessage()), previous: $exception);
            }

            if ($eventsPersisted) {
                try {
                    $this->hotPromptStateRebuilder->rebuildHotPromptState($nextState->runId);
                } catch (\Throwable $exception) {
                    // Hot prompt rebuild is best-effort — the previous
                    // hot state is still valid. Log the failure so
                    // operators can diagnose persistent rebuild issues.
                    $this->logger->warning('Hot prompt state rebuild failed (best-effort)', [
                        'run_id' => $nextState->runId,
                        'turn_no' => $nextState->turnNo,
                        'step_id' => $nextState->activeStepId,
                        'exception' => $exception,
                    ]);
                }
            }

            $this->logCommittedEvents($nextState, $events);

            if ([] !== $effects) {
                try {
                    $this->stepDispatcher->dispatchEffects($effects);
                } catch (\Throwable $exception) {
                    // Effect dispatch is best-effort side work.
                    // The primary commit (state + events) succeeded.
                    $this->logger->warning('Effect dispatch failed after successful commit (best-effort)', [
                        'run_id' => $nextState->runId,
                        'turn_no' => $nextState->turnNo,
                        'step_id' => $nextState->activeStepId,
                        'effects_count' => \count($effects),
                        'exception' => $exception,
                    ]);
                }
            }

            try {
                $this->hookDispatcher?->dispatchAfterTurnCommit(
                    AfterTurnCommitHookContext::fromRunState($nextState, $events, \count($effects)),
                );
            } catch (\Throwable $exception) {
                // After-turn commit hooks are optional extension points.
                // A failing hook must not roll back the commit.
                $this->logger->warning('After-turn commit hook failed (best-effort)', [
                    'run_id' => $nextState->runId,
                    'turn_no' => $nextState->turnNo,
                    'step_id' => $nextState->activeStepId,
                    'exception' => $exception,
                ]);
            }

            return true;
        };

        $committed = null === $this->tracer
            ? $persist()
            : $this->tracer->inSpan('persistence.commit', [
                'run_id' => $nextState->runId,
                'turn_no' => $nextState->turnNo,
                'step_id' => $nextState->activeStepId,
                'event_count' => \count($events),
                'effects_count' => \count($effects),
            ], $persist)
        ;

        if (!$committed) {
            return false;
        }

        $this->trackCommitMetrics($state, $nextState, $events);

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
