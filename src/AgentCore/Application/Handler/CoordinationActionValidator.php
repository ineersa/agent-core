<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Domain\Coordination\ConsumeExecutionUnknownDTO;
use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
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
        if ($action instanceof FinalizeToolBatchDTO) {
            if ('' === $action->runId || '' === $action->stepId || $action->turnNo < 0
                || !array_is_list($action->pendingQueue)) {
                throw new \RuntimeException('Invalid prepared batch coordination identity.');
            }
            foreach ($action->pendingQueue as $id) {
                if (!\is_string($id) || '' === $id) {
                    throw new \RuntimeException('Invalid prepared batch queue.');
                }
            }
            foreach ($action->inFlight as $id => $value) {
                if (true !== $value) {
                    throw new \RuntimeException('Invalid prepared batch in-flight state.');
                }
            }
            foreach ($action->awaitingHumanInput as $question) {
                if (!\is_string($question) || '' === $question) {
                    throw new \RuntimeException('Invalid prepared batch human-input state.');
                }
            }
            if (null !== $action->result && ($action->result->runId() !== $action->runId || $action->result->turnNo() !== $action->turnNo || $action->result->stepId() !== $action->stepId)) {
                throw new \RuntimeException('Prepared batch result differs from its invocation.');
            }
            if (null === $action->revisedCallId && null !== $action->answer) {
                throw new \RuntimeException('Prepared batch answer has no invocation.');
            }

            return;
        }
        if ($action instanceof DispatchCoordinationMessageDTO) {
            // Its closed control-message field excludes external execution.
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
            || $action instanceof ConsumeExecutionUnknownDTO || $action instanceof RetireUnknownExecutionDTO) {
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
