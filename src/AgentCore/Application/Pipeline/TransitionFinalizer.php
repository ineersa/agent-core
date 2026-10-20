<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunTracer;
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
        private ?RunTracer $tracer = null,
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
        $this->probe('coordination', $plan->runId, fn () => $this->dispatcher->dispatchCoordinationActions($plan->syncActions, $plan->verified));
        if (null !== $plan->verified) {
            $this->probe('local_metadata', $plan->runId, fn () => $this->localMetadata->apply($plan));
            $this->probe('canonical_finalize', $plan->runId, fn () => $this->store->finalizeVerifiedTransition($plan->runId, $plan->verified));
        } elseif ([] !== $plan->localActions) {
            throw new \RuntimeException('Local metadata requires verified transition evidence.');
        }
        // The journal owns unfinished canonical coordination, not a retry queue.
        // A transport loss after this boundary requires explicit /repair.
        $this->locks->afterRelease($plan->runId, function () use ($plan): void {
            $this->probe('effects_after_release', $plan->runId, fn () => $this->dispatcher->dispatchEffects($plan->messages));
        });
    }

    /** @param callable(): void $operation */
    private function probe(string $phase, string $runId, callable $operation): void
    {
        if (null === $this->tracer) {
            $operation();

            return;
        }

        $this->tracer->inSpan('latency_probe.finalize.'.$phase, ['latency_probe' => true, 'run_id' => $runId], $operation);
    }
}
