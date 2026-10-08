<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CommandMailboxCoordinationFactory;
use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Symfony\Component\Messenger\Envelope;

/** Classifies captured transition work once for normal commit and recovery. */
final readonly class TransitionPlanFactory
{
    /**
     * @param list<object> $effects
     * @param list<object> $actions
     * @param list<object> $afterTurnActions
     */
    public function create(string $runId, ?VerifiedTransitionDTO $verified, array $effects, array $actions, array $afterTurnActions = []): TransitionPlan
    {
        $local = [];
        $sync = [];
        $messages = $effects;
        foreach ([...$actions, ...$afterTurnActions] as $action) {
            if ($action instanceof FinalizeToolBatchDTO || $action instanceof RegisterToolBatchDTO || CommandMailboxCoordinationFactory::isMailboxAction($action)) {
                $local[] = $action;
                if ($action instanceof RegisterToolBatchDTO) {
                    // Initial admission was decided by the collector. Queued calls
                    // must not bypass parallel capacity or a sequential barrier.
                    foreach ($action->effects as $call) {
                        if (isset($action->inFlight[$call->toolCallId])) {
                            $messages[] = $call;
                        }
                    }
                }
            } elseif ($action instanceof DispatchCoordinationMessageDTO) {
                $messages[] = $action->message;
            } else {
                $sync[] = $action;
            }
        }
        foreach ($messages as $message) {
            $payload = $message instanceof Envelope ? $message->getMessage() : $message;
            if (!$payload instanceof AbstractAgentBusMessage) {
                throw new \RuntimeException('Unsupported owner transition message.');
            }
        }

        return new TransitionPlan($runId, $verified, $local, $sync, $messages);
    }
}
