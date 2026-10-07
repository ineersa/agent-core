<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CommandMailboxCoordinationFactory;
use Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;

/** One completion path for normal commits, recovery, and event-free dispositions. */
final readonly class TransitionFinalizer
{
    public function __construct(
        private PreparedTransitionEventStoreInterface $store,
        private ExecutionOperationStoreInterface $executionOperations,
        private StepDispatcher $dispatcher,
        private SourceAcceptance $sourceAcceptance,
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
        if (null !== $executionDisposition) {
            if (null === $verified) {
                throw new \RuntimeException('Execution authorization requires verified transition evidence.');
            }
            $this->executionOperations->validateDisposition($executionDisposition, $verified);
        }

        $batchActions = array_values(array_filter($actions, static fn (object $action): bool => $action instanceof FinalizeToolBatchDTO));
        $this->dispatcher->dispatchCoordinationActions($batchActions);
        $actions = array_values(array_filter($actions, static fn (object $action): bool => !$action instanceof FinalizeToolBatchDTO));

        $this->dispatcher->dispatchCoordinationActions($afterTurnActions);

        $mailboxActions = array_values(array_filter($actions, CommandMailboxCoordinationFactory::isMailboxAction(...)));
        $this->dispatcher->dispatchCoordinationActions($mailboxActions);
        $actions = array_values(array_filter($actions, static fn (object $action): bool => !CommandMailboxCoordinationFactory::isMailboxAction($action)));

        $ordinary = [];
        $deliveries = [];
        $stamps = [];
        foreach ($effects as $effect) {
            if ($effect instanceof AbstractAgentBusMessage && ExecutionOperationMapper::supports($effect)) {
                if (null === $verified) {
                    throw new \RuntimeException('Execution authorization requires verified transition evidence.');
                }
                $authorization = $this->executionOperations->arm($effect, $verified);
                $reference = $this->executionOperations->requestReference($effect, $authorization);
                $deliveries[] = $reference;
                $stamps[spl_object_id($reference)] = $authorization;
                continue;
            }
            $ordinary[] = $effect;
        }

        foreach ($actions as $action) {
            if (!$action instanceof \Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO) {
                continue;
            }
            if (null === $verified) {
                throw new \RuntimeException('Execution authorization requires verified transition evidence.');
            }
            foreach ($action->effects as $effect) {
                if (!$effect instanceof ExecuteToolCall) {
                    continue;
                }
                $authorization = $this->executionOperations->arm($effect, $verified);
                $reference = $this->executionOperations->requestReference($effect, $authorization);
                $deliveries[] = $reference;
                $stamps[spl_object_id($reference)] = $authorization;
            }
        }

        $this->dispatcher->dispatchEffects($ordinary);
        $this->dispatcher->dispatchCoordinationActions($actions);

        if (null !== $executionDisposition) {
            $this->executionOperations->applyDisposition($executionDisposition, $verified);
        }
        if (null !== $verified) {
            $this->sourceAcceptance->publish($verified);
            $this->store->finalizeVerifiedTransition($runId, $verified->identity);
        }

        $this->dispatcher->dispatchEffects($deliveries, $stamps);
    }
}
