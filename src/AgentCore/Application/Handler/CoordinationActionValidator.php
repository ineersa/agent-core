<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Domain\Coordination\ConsumeExecutionUnknownDTO;
use Ineersa\AgentCore\Domain\Coordination\ConsumeToolExecutionUnknownDTO;
use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RejectCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class CoordinationActionValidator
{
    /** @param iterable<CoordinationActionValidatorInterface> $validators */
    public function __construct(#[AutowireIterator('agent_core.coordination_action_validator')] private iterable $validators = [])
    {
    }

    public function validate(object $action): void
    {
        if ($action instanceof DispatchCoordinationMessageDTO) {
            // Its closed AdvanceRun|CompactRun field excludes external execution.
            return;
        }
        if ($action instanceof RegisterToolBatchDTO) {
            foreach ($action->effects as $call) {
                if (!$call instanceof ExecuteToolCall || $call->runId() !== $action->runId || $call->turnNo() !== $action->turnNo || $call->stepId() !== $action->stepId) {
                    throw new \RuntimeException('Prepared tool batch contains a mismatching invocation.');
                }
            }

            return;
        }
        if ($action instanceof MarkCommandAppliedDTO || $action instanceof EnqueueCommandDTO || $action instanceof RejectCommandDTO
            || $action instanceof ConsumeExecutionUnknownDTO || $action instanceof ConsumeToolExecutionUnknownDTO || $action instanceof RetireUnknownExecutionDTO) {
            return;
        }
        foreach ($this->validators as $validator) {
            if ($validator->supports($action)) {
                $validator->validate($action);

                return;
            }
        }

        throw new \RuntimeException('Owner transition requires coordination recovery for unsupported action.');
    }
}
