<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CommandMailboxCoordinationFactory;
use Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper;
use Ineersa\AgentCore\Domain\Coordination\ConsumeExecutionUnknownDTO;
use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\RunControlTransitionMessageInterface;
use Symfony\Component\Messenger\Envelope;

/** Classifies captured transition work once for the shared finalizer. */
final readonly class TransitionPlanFactory
{
    /**
     * @param list<object> $effects
     * @param list<object> $actions
     * @param list<object> $afterTurnActions
     */
    public function create(
        string $runId,
        ?VerifiedTransitionDTO $verified,
        array $effects,
        array $actions,
        array $afterTurnActions = [],
        ?ExecutionResultDispositionDTO $executionDisposition = null,
    ): TransitionPlan {
        $local = [];
        $sync = [];
        $control = [];
        foreach ([...$actions, ...$afterTurnActions] as $action) {
            if ($this->isLocalMetadataAction($action)) {
                $local[] = $action;
                continue;
            }
            if ($action instanceof DispatchCoordinationMessageDTO) {
                $control[] = $action;
                continue;
            }
            // App domain coordinators (child repair, observation, lifecycle markers)
            // stay under the owner lock before cut publication.
            $sync[] = $action;
        }

        $gated = [];
        foreach ($effects as $effect) {
            if ($effect instanceof AbstractAgentBusMessage && ExecutionOperationMapper::supports($effect)) {
                $gated[] = $effect;
                continue;
            }
            if ($effect instanceof Envelope) {
                $control[] = $effect;
                continue;
            }
            if ($effect instanceof DispatchCoordinationMessageDTO) {
                $control[] = $effect;
                continue;
            }
            if ($effect instanceof RunControlTransitionMessageInterface) {
                $control[] = $effect;
                continue;
            }
            throw new \RuntimeException('Owner transition requires coordination recovery for ungated execution.');
        }

        return new TransitionPlan($runId, $verified, $local, $sync, $control, $gated, $executionDisposition);
    }

    private function isLocalMetadataAction(object $action): bool
    {
        return $action instanceof FinalizeToolBatchDTO
            || $action instanceof RegisterToolBatchDTO
            || $action instanceof RetireUnknownExecutionDTO
            || $action instanceof ConsumeExecutionUnknownDTO
            || CommandMailboxCoordinationFactory::isMailboxAction($action);
    }
}
