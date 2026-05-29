<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Runtime\ErrorCapture\RuntimeErrorCaptureService;
use Psr\Log\LoggerInterface;

final readonly class RunCommit
{
    public function __construct(
        private RunStoreInterface $runStore,
        private EventStoreInterface $eventStore,
        private CommandStoreInterface $commandStore,
        private ReplayService $replayService,
        private StepDispatcher $stepDispatcher,
        private LoggerInterface $logger,
        private RuntimeErrorCaptureService $errorCapture,
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
                    if (1 === \count($events)) {
                        $this->eventStore->append($events[0]);
                    } else {
                        $this->eventStore->appendMany($events);
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
                } catch (\Throwable) {
                    // Best effort — cannot mark failed in store.
                }

                $this->errorCapture->handleError($exception, 'agent_loop.commit.event_persist_failed', [
                    'run_id' => $nextState->runId,
                    'turn_no' => $nextState->turnNo,
                    'step_id' => $nextState->activeStepId,
                    'event_count' => \count($events),
                    'rollback_restored' => $rollbackRestored,
                    'rollback_error' => $rollbackError,
                ]);

                // If we reach here, capture is enabled — throw a
                // terminal exception so the message processor does
                // NOT retry (this is not a CAS conflict).
                throw new \RuntimeException(\sprintf('Event persistence failed for run %s turn %d: %s', $nextState->runId, $nextState->turnNo, $exception->getMessage()), previous: $exception);
            }

            if ($eventsPersisted) {
                try {
                    $this->replayService->rebuildHotPromptState($nextState->runId);
                } catch (\Throwable $exception) {
                    $this->errorCapture->handleDegradation($exception, 'agent_loop.commit.hot_state_rebuild_failed', [
                        'run_id' => $nextState->runId,
                        'turn_no' => $nextState->turnNo,
                        'step_id' => $nextState->activeStepId,
                    ]);
                }
            }

            $this->logCommittedEvents($nextState, $events);

            if ([] !== $effects) {
                try {
                    $this->stepDispatcher->dispatchEffects($effects);
                } catch (\Throwable $exception) {
                    $this->errorCapture->handleDegradation($exception, 'agent_loop.commit.effect_dispatch_failed', [
                        'run_id' => $nextState->runId,
                        'turn_no' => $nextState->turnNo,
                        'step_id' => $nextState->activeStepId,
                        'effects_count' => \count($effects),
                    ]);
                }
            }

            try {
                $this->hookDispatcher?->dispatchAfterTurnCommit(
                    AfterTurnCommitHookContext::fromRunState($nextState, $events, \count($effects)),
                );
            } catch (\Throwable $exception) {
                $this->errorCapture->handleDegradation($exception, 'agent_loop.commit.after_turn_commit_hook_failed', [
                    'run_id' => $nextState->runId,
                    'turn_no' => $nextState->turnNo,
                    'step_id' => $nextState->activeStepId,
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

        foreach ($events as $event) {
            $this->logger->info('agent_loop.event', [
                'run_id' => $event->runId,
                'turn_no' => $event->turnNo,
                'step_id' => $this->eventStepId($event, $state),
                'seq' => $event->seq,
                'status' => $state->status->value,
                'worker_id' => $this->eventWorkerId($event),
                'attempt' => $this->eventAttempt($event),
            ]);
        }
    }

    private function eventStepId(RunEvent $event, RunState $state): ?string
    {
        if (\is_string($event->payload['step_id'] ?? null) && '' !== $event->payload['step_id']) {
            return $event->payload['step_id'];
        }

        if (\is_string($event->payload['stepId'] ?? null) && '' !== $event->payload['stepId']) {
            return $event->payload['stepId'];
        }

        return $state->activeStepId;
    }

    private function eventWorkerId(RunEvent $event): string
    {
        if (\is_string($event->payload['worker_id'] ?? null) && '' !== $event->payload['worker_id']) {
            return $event->payload['worker_id'];
        }

        return 'orchestrator';
    }

    private function eventAttempt(RunEvent $event): ?int
    {
        $attempt = $event->payload['attempt'] ?? null;

        if (\is_int($attempt)) {
            return $attempt;
        }

        if (\is_string($attempt) && ctype_digit($attempt)) {
            return (int) $attempt;
        }

        return null;
    }
}
