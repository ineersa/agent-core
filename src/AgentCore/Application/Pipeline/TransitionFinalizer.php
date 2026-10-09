<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;

/** One completion path for normal commits and unfinished canonical transitions. */
final readonly class TransitionFinalizer
{
    public function __construct(
        private PreparedTransitionEventStoreInterface $store,
        private StepDispatcher $dispatcher,
        private LocalMetadataCoordinator $localMetadata,
        private TransitionPlanFactory $plans,
        private RunLockManager $locks,
    ) {
    }

    /**
     * @param list<object> $effects
     * @param list<object> $actions
     * @param list<object> $afterTurnActions
     */
    public function complete(string $runId, ?VerifiedTransitionDTO $verified, array $effects, array $actions, array $afterTurnActions = []): void
    {
        $this->completePlan($this->plans->create($runId, $verified, $effects, $actions, $afterTurnActions));
    }

    public function completePlan(TransitionPlan $plan): void
    {
        $this->dispatcher->dispatchCoordinationActions($plan->syncActions);
        if (null !== $plan->verified) {
            $this->localMetadata->apply($plan);
            $this->store->finalizeVerifiedTransition($plan->runId, $plan->verified->identity);
        } elseif ([] !== $plan->localActions) {
            throw new \RuntimeException('Local metadata requires verified transition evidence.');
        }
        // The journal owns unfinished canonical coordination, not a retry queue.
        // A transport loss after this boundary requires explicit /repair.
        $this->locks->afterRelease($plan->runId, function () use ($plan): void {
            $this->dispatcher->dispatchEffects($plan->messages);
        });
    }
}
