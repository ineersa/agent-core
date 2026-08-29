<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Psr\Log\LoggerInterface;

final readonly class RunCommit
{
    public function __construct(
        private ActiveRunContextInterface $activeRunContext,
        private EventStoreInterface $eventStore,
        private StepDispatcher $stepDispatcher,
        private LoggerInterface $logger,
        private ?HookDispatcher $hookDispatcher = null,
        private ?RunTracer $tracer = null,
    ) {
    }

    /**
     * Canonical events are authoritative. The projection and process-local
     * context are replaced only after their append has completed, before any
     * effect or extension hook can observe the transition.
     *
     * @param list<RunEvent> $events
     * @param list<object>   $effects
     */
    public function commit(RunState $state, RunState $nextState, array $events, array $effects = []): void
    {
        $persist = function () use ($nextState, $events, $effects): RunState {
            /** @var list<RunEvent> $persistedEvents */
            $persistedEvents = [];
            if ([] !== $events) {
                $persistedEvents = 1 === \count($events)
                    ? [$this->eventStore->append($events[0])]
                    : $this->eventStore->appendMany($events);
            }

            $committedState = $nextState;
            if ([] !== $persistedEvents) {
                $lastPersisted = $persistedEvents[array_key_last($persistedEvents)];
                $committedState = $nextState->with([
                    // This is a bounded diagnostic/projection counter only;
                    // session-owner serialization replaces CAS authority.
                    'version' => $nextState->version + 1,
                    'lastSeq' => $lastPersisted->seq,
                ]);
            }

            // remember() persists the narrow projection before publishing the
            // full state in memory and invalidates memory if persistence fails.
            $this->activeRunContext->remember($committedState);

            $this->logCommittedEvents($committedState, $persistedEvents);

            if ([] !== $effects) {
                try {
                    $this->stepDispatcher->dispatchEffects($effects);
                } catch (\Throwable $exception) {
                    $this->logger->warning('Effect dispatch failed after successful commit (best-effort)', [
                        'run_id' => $committedState->runId,
                        'turn_no' => $committedState->turnNo,
                        'step_id' => $committedState->activeStepId,
                        'effects_count' => \count($effects),
                        'exception' => $exception,
                    ]);
                }
            }

            try {
                $this->hookDispatcher?->dispatchAfterTurnCommit(
                    AfterTurnCommitHookContext::fromRunState($committedState, $persistedEvents, \count($effects)),
                );
            } catch (\Throwable $exception) {
                $this->logger->warning('After-turn commit hook failed (best-effort)', [
                    'run_id' => $committedState->runId,
                    'turn_no' => $committedState->turnNo,
                    'step_id' => $committedState->activeStepId,
                    'exception' => $exception,
                ]);
            }

            return $committedState;
        };

        if (null === $this->tracer) {
            $persist();

            return;
        }

        $this->tracer->inSpan('persistence.commit', [
            'run_id' => $nextState->runId,
            'turn_no' => $nextState->turnNo,
            'step_id' => $nextState->activeStepId,
            'event_count' => \count($events),
            'effects_count' => \count($effects),
        ], $persist);
    }

    /** @param list<RunEvent> $events */
    private function logCommittedEvents(RunState $state, array $events): void
    {
        if ([] === $events) {
            return;
        }

        $eventsByType = [];
        foreach ($events as $event) {
            $eventsByType[$event->type] = ($eventsByType[$event->type] ?? 0) + 1;
        }

        $this->logger->info('persistence.events_committed', [
            'run_id' => $state->runId,
            'turn_no' => $state->turnNo,
            'event_count' => \count($events),
            'events_by_type' => $eventsByType,
            'new_status' => $state->status->value,
            'component' => 'storage',
        ]);
    }
}
