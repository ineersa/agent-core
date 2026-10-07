<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper;
use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Psr\Log\LoggerInterface;

final readonly class RunCommit
{
    private TransitionFinalizer $finalizer;

    public function __construct(
        private ActiveRunContextInterface $activeRunContext,
        private PreparedTransitionEventStoreInterface $eventStore,
        private LoggerInterface $logger,
        private ToolBatchCollector $toolBatchCollector,
        private ExecutionOperationStoreInterface $executionOperations,
        private SourceAcceptance $sourceAcceptance,
        private ?HookDispatcher $hookDispatcher = null,
        private ?RunTracer $tracer = null,
        private \Ineersa\AgentCore\Application\Handler\CoordinationActionValidator $actionValidator = new \Ineersa\AgentCore\Application\Handler\CoordinationActionValidator(),
        ?TransitionFinalizer $finalizer = null,
    ) {
        if (null === $finalizer) {
            throw new \LogicException('Configured TransitionFinalizer is required for local metadata commits.');
        }
        $this->finalizer = $finalizer;
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
    public function commit(RunState $state, RunState $nextState, array $events, array $effects = [], bool $dispatchAfterTurnHooks = true, array $postCommitEffects = [], array $postCommitActions = [], array $sourceIdentity = [], ?ExecutionResultDispositionDTO $executionDisposition = null): RunState
    {
        $this->assertTransitionReady($state->runId);
        $afterTurnActions = [];
        if ($dispatchAfterTurnHooks) {
            $afterTurnActions = $this->hookDispatcher?->prepareAfterTurnCommit(
                AfterTurnCommitHookContext::fromRunState($nextState, $events, \count($effects)), $state->lastSeq,
            ) ?? [];
        }
        foreach ([...$postCommitActions, ...$afterTurnActions] as $action) {
            $this->actionValidator->validate($action);
        }
        foreach ([...$effects, ...$postCommitEffects] as $effect) {
            if ($effect instanceof \Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage
                && ExecutionOperationMapper::supports($effect)) {
                $this->executionOperations->assertRequestCapacity($effect);
            }
        }
        $persist = function () use ($state, $nextState, $events, $effects, $afterTurnActions, $dispatchAfterTurnHooks, $postCommitEffects, $postCommitActions, $sourceIdentity, $executionDisposition): RunState {
            /** @var list<RunEvent> $persistedEvents */
            $persistedEvents = [];
            if ([] !== $sourceIdentity || [] !== $events || null !== $executionDisposition || [] !== $effects || [] !== $postCommitEffects || [] !== $postCommitActions || [] !== $afterTurnActions) {
                $persistedEvents = $this->eventStore->appendTransition($events, ['run_id' => $nextState->runId, 'predecessor_seq' => $state->lastSeq, 'source' => $sourceIdentity, 'effects' => $effects, 'post_commit_effects' => $postCommitEffects, 'actions' => $postCommitActions, 'after_turn_actions' => $afterTurnActions, 'execution_disposition' => $executionDisposition]);
            }
            $verifiedSource = $this->eventStore->verifiedPendingTransition($nextState->runId);
            if (null !== $verifiedSource) {
                $this->sourceAcceptance->validate($verifiedSource);
                $postCommitActions = $verifiedSource->work['actions'] ?? [];
                $afterTurnActions = $verifiedSource->work['after_turn_actions'] ?? [];
                foreach ([...$postCommitActions, ...$afterTurnActions] as $action) {
                    $this->actionValidator->validate($action);
                }
            }

            $committedState = $nextState;
            if ([] !== $persistedEvents) {
                $lastPersisted = $persistedEvents[array_key_last($persistedEvents)];
                $committedState = $nextState->with([
                    'version' => $nextState->version + 1,
                    'lastSeq' => $lastPersisted->seq,
                ]);
            }

            $this->activeRunContext->replaceCurrent($committedState);
            $this->toolBatchCollector->releaseAfterCommit($committedState, $persistedEvents);
            $this->logCommittedEvents($committedState, $persistedEvents);

            $this->finalizer->complete(
                $committedState->runId,
                $verifiedSource,
                [...$effects, ...$postCommitEffects],
                $postCommitActions,
                $afterTurnActions,
                $executionDisposition,
            );

            if (!$dispatchAfterTurnHooks) {
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

    public function executionResultAlreadyDisposed(?DurableExecutionResult $reference): bool
    {
        return null !== $reference && $this->executionOperations->isDisposed($reference);
    }

    public function prepareExecutionDisposition(?DurableExecutionResult $reference, bool $stale): ?ExecutionResultDispositionDTO
    {
        return null === $reference ? null : new ExecutionResultDispositionDTO($reference, $stale ? 'Stale' : 'Consumed');
    }

    public function assertTransitionReady(string $runId): void
    {
        $this->eventStore->assertTransitionReady($runId);
    }

    public function assertNoUnknownExecution(string $runId): void
    {
        $this->executionOperations->assertNoUnknownExecution($runId);
    }

    public function sourceAlreadyAccepted(\Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $message): bool
    {
        return $this->sourceAcceptance->alreadyAccepted($message);
    }

    /** @param array<string, int|string> $identity */
    public function sourceIdentityAlreadyAccepted(array $identity): bool
    {
        return $this->sourceAcceptance->identityAlreadyAccepted($identity);
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
