<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CommandMailboxCoordinationFactory;
use Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;

/** One completion path for normal commits, recovery, and event-free dispositions. */
final readonly class TransitionFinalizer
{
    public function __construct(
        private PreparedTransitionEventStoreInterface $store,
        private StepDispatcher $dispatcher,
        private LocalMetadataCoordinator $localMetadata,
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
        $localActions = [];
        $remainingActions = [];
        foreach ($actions as $action) {
            if ($this->isLocalMetadataAction($action)) {
                $localActions[] = $action;
                continue;
            }
            $remainingActions[] = $action;
        }

        // Application lifecycle actions stay outside the local metadata transaction.
        $this->dispatcher->dispatchCoordinationActions($afterTurnActions);

        $ordinary = [];
        /** @var list<AbstractAgentBusMessage> $gated */
        $gated = [];
        foreach ($effects as $effect) {
            if ($effect instanceof AbstractAgentBusMessage && ExecutionOperationMapper::supports($effect)) {
                $gated[] = $effect;
                continue;
            }
            $ordinary[] = $effect;
        }

        $this->dispatcher->dispatchEffects($ordinary);
        $this->dispatcher->dispatchCoordinationActions($remainingActions);

        $deliveries = [];
        $stamps = [];
        if (null !== $verified) {
            [$deliveries, $stamps] = $this->localMetadata->apply($verified, $localActions, $gated, $executionDisposition);
            $this->store->finalizeVerifiedTransition($runId, $verified->identity);
        } elseif (null !== $executionDisposition || [] !== $localActions || [] !== $gated) {
            throw new \RuntimeException('Execution authorization requires verified transition evidence.');
        }

        $this->dispatcher->dispatchEffects($deliveries, $stamps);
    }

    private function isLocalMetadataAction(object $action): bool
    {
        return $action instanceof FinalizeToolBatchDTO
            || $action instanceof RegisterToolBatchDTO
            || CommandMailboxCoordinationFactory::isMailboxAction($action);
    }
}
