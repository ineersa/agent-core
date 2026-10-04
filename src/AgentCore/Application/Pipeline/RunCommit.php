<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Psr\Log\LoggerInterface;

final readonly class RunCommit
{
    public function __construct(
        private ActiveRunContextInterface $activeRunContext,
        private PreparedTransitionEventStoreInterface $eventStore,
        private StepDispatcher $stepDispatcher,
        private LoggerInterface $logger,
        private ToolBatchCollector $toolBatchCollector,
        private \Ineersa\AgentCore\Contract\Tool\ToolExecutionAuthorizationInterface $toolAuthorization,
        private \Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface $executionOperations,
        private ?HookDispatcher $hookDispatcher = null,
        private ?RunTracer $tracer = null,
        private \Ineersa\AgentCore\Application\Handler\ExecutionBoundaryContext $executionContext = new \Ineersa\AgentCore\Application\Handler\ExecutionBoundaryContext(),
    ) {
    }

    /**
     * Canonical events are authoritative. The projection and process-local
     * context are replaced only after their append has completed, before any
     * effect or extension hook can observe the transition.
     *
     * @param list<RunEvent>            $events
     * @param list<object>              $effects
     * @param list<object>              $postCommitEffects
     * @param list<object>              $postCommitActions
     * @param array<string, int|string> $sourceIdentity
     */
    public function commit(RunState $state, RunState $nextState, array $events, array $effects = [], bool $dispatchAfterTurnHooks = true, array $postCommitEffects = [], array $postCommitActions = [], array $sourceIdentity = [], ?\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO $resultDisposition = null, ?\Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO $executionDisposition = null): RunState
    {
        $this->assertTransitionReady($state->runId);
        $persist = function () use ($state, $nextState, $events, $effects, $dispatchAfterTurnHooks, $postCommitEffects, $postCommitActions, $sourceIdentity, $resultDisposition, $executionDisposition): RunState {
            /** @var list<RunEvent> $persistedEvents */
            $persistedEvents = [];
            if ([] !== $events || null !== $resultDisposition || null !== $executionDisposition || [] !== $effects || [] !== $postCommitEffects || [] !== $postCommitActions) {
                $persistedEvents = $this->eventStore->appendTransition($events, ['run_id' => $nextState->runId, 'predecessor_seq' => $state->lastSeq, 'source' => $sourceIdentity, 'effects' => $effects, 'post_commit_effects' => $postCommitEffects, 'actions' => $postCommitActions, 'after_turn_hooks' => $dispatchAfterTurnHooks, 'result_disposition' => $resultDisposition, 'execution_disposition' => $executionDisposition]);
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

            // replaceCurrent() persists the narrow projection before publishing the
            // full state in memory and invalidates memory if persistence fails.
            $this->activeRunContext->replaceCurrent($committedState);

            $this->toolBatchCollector->releaseAfterCommit($committedState, $persistedEvents);

            $this->logCommittedEvents($committedState, $persistedEvents);

            $ordinaryEffects = array_values(array_filter($effects, static fn (object $effect): bool => !\Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper::supports($effect)));
            $gatedEffects = array_values(array_filter($effects, \Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper::supports(...)));
            if ([] !== $ordinaryEffects) {
                $this->armTools($ordinaryEffects);
                $this->stepDispatcher->dispatchEffects($ordinaryEffects);
            }

            // History maintenance publishes canonical state without scheduling a
            // completed-turn continuation, matching its former raw-append semantics.
            if (!$dispatchAfterTurnHooks) {
                $this->finishTransition($committedState->runId, $persistedEvents, [...$gatedEffects, ...$postCommitEffects], $postCommitActions, $resultDisposition, $executionDisposition);

                return $committedState;
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

            $this->finishTransition($committedState->runId, $persistedEvents, [...$gatedEffects, ...$postCommitEffects], $postCommitActions, $resultDisposition, $executionDisposition);

            return $committedState;
        };

        if (null === $this->tracer) {
            return $persist();
        }

        return $this->tracer->inSpan('persistence.commit', [
            'run_id' => $nextState->runId,
            'turn_no' => $nextState->turnNo,
            'step_id' => $nextState->activeStepId,
            'event_count' => \count($events),
            'effects_count' => \count($effects),
        ], $persist);
    }

    public function toolResultAlreadyDisposed(\Ineersa\AgentCore\Domain\Message\ToolCallResult $result): bool
    {
        return $this->toolAuthorization->isDisposed($result);
    }

    public function executionResultAlreadyDisposed(): bool
    {
        $reference = $this->executionContext->currentResult();

        return null !== $reference && $this->executionOperations->isDisposed($reference);
    }

    public function prepareExecutionDisposition(bool $stale): ?\Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO
    {
        $reference = $this->executionContext->currentResult();

        return null === $reference ? null : new \Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO($reference, $stale ? 'Stale' : 'Consumed');
    }

    public function prepareToolDisposition(\Ineersa\AgentCore\Domain\Message\ToolCallResult $result, bool $stale): ?\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO
    {
        return $this->toolAuthorization->prepareDisposition($result, $stale ? 'Stale' : 'Consumed');
    }

    public function finishEventFreeDisposition(\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO $descriptor, HandlerResult $result): void
    {
        $this->assertTransitionReady($descriptor->runId);
        $state = $this->activeRunContext->requireLoaded($descriptor->runId);
        $this->eventStore->appendTransition([], ['run_id' => $descriptor->runId, 'predecessor_seq' => $state->lastSeq, 'source' => ['type' => 'tool_result_disposition', 'result_hash' => $descriptor->resultHash], 'effects' => [], 'post_commit_effects' => $result->postCommitEffects, 'actions' => $result->postCommitActions, 'result_disposition' => $descriptor]);
        $this->finishTransition($descriptor->runId, [], $result->postCommitEffects, $result->postCommitActions, $descriptor);
    }

    public function assertTransitionReady(string $runId): void
    {
        $this->eventStore->assertTransitionReady($runId);
    }

    /** @param list<RunEvent> $events
     * @param list<object> $effects
     * @param list<object> $actions
     */
    private function finishTransition(string $runId, array $events, array $effects, array $actions, ?\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO $resultDisposition, ?\Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO $executionDisposition = null): void
    {
        $ordinary = array_values(array_filter($effects, static fn (object $effect): bool => !\Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper::supports($effect)));
        $gated = array_values(array_filter($effects, \Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper::supports(...)));
        $verified = null;
        $stamps = [];
        $deliveries = [];
        if ([] !== $gated || null !== $resultDisposition || null !== $executionDisposition) {
            $verified = $this->eventStore->verifiedPendingTransition($runId);
            if (null === $verified) {
                throw new \RuntimeException('Execution authorization requires verified transition evidence.');
            }
            foreach ($gated as $effect) {
                if (!$effect instanceof \Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage) {
                    throw new \LogicException('Invalid execution effect.');
                }
                $authorization = $this->executionOperations->arm($effect, $verified);
                $reference = $this->executionOperations->requestReference($effect, $authorization);
                $deliveries[] = $reference;
                $stamps[spl_object_id($reference)] = $authorization;
            }
            if (null !== $executionDisposition) {
                $this->executionOperations->validateDisposition($executionDisposition, $verified);
            }
        }
        $this->armTools($ordinary);
        $this->stepDispatcher->dispatchEffects($ordinary);
        $this->stepDispatcher->dispatchCoordinationActions($actions);
        if (null !== $resultDisposition) {
            if (null === $verified) {
                throw new \RuntimeException('Result disposition requires verified transition evidence.');
            }
            $this->toolAuthorization->applyDisposition($resultDisposition, $verified);
        }
        if (null !== $executionDisposition && null !== $verified) {
            $this->executionOperations->applyDisposition($executionDisposition, $verified);
        }
        if (null !== $verified) {
            $this->eventStore->finalizeVerifiedTransition($runId, $verified->identity);
        } elseif ([] !== $events || [] !== $effects || [] !== $actions) {
            $this->eventStore->finalizeTransition($runId);
        }
        // Armed records retain the original request when broker delivery fails.
        // The execution gate, not an enqueue acknowledgement, grants one claim.
        $this->stepDispatcher->dispatchEffects($deliveries, $stamps);
    }

    /** @param list<object> $effects */
    private function armTools(array $effects): void
    {
        foreach ($effects as $effect) {
            if ($effect instanceof \Ineersa\AgentCore\Domain\Message\ExecuteToolCall) {
                $this->toolAuthorization->arm($effect);
            }
        }
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
