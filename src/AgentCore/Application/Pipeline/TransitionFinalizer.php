<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;

/** One completion path for normal commits, recovery, and event-free dispositions. */
final readonly class TransitionFinalizer
{
    public function __construct(
        private PreparedTransitionEventStoreInterface $store,
        private StepDispatcher $dispatcher,
        private LocalMetadataCoordinator $localMetadata,
        private TransitionPlanFactory $plans,
        private DurablePendingPublication $publication,
    ) {
    }

    /**
     * @param list<object> $effects
     * @param list<object> $actions
     * @param list<object> $afterTurnActions
     */
    public function complete(
        string $runId,
        ?VerifiedTransitionDTO $verified,
        array $effects,
        array $actions,
        array $afterTurnActions = [],
        ?ExecutionResultDispositionDTO $executionDisposition = null,
    ): void {
        $this->completePlan($this->plans->create($runId, $verified, $effects, $actions, $afterTurnActions, $executionDisposition));
    }

    public function completePlan(TransitionPlan $plan): void
    {
        // Synchronous App domain coordination finishes before cut publication.
        $this->dispatcher->dispatchCoordinationActions($plan->syncActions);

        if (null !== $plan->verified) {
            $this->localMetadata->apply($plan);
            $this->store->finalizeVerifiedTransition($plan->runId, $plan->verified->identity);
        } elseif (null !== $plan->executionDisposition || [] !== $plan->localActions || [] !== $plan->gatedEffects || [] !== $plan->controlActions) {
            throw new \RuntimeException('Execution authorization requires verified transition evidence.');
        }

        // Broker sends leave the owner lock through the shared publication path.
        $this->publication->scheduleAfterOwnerLock($plan->runId);
    }
}
