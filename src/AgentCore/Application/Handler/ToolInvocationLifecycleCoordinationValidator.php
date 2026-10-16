<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationLifecycleTransition;

/**
 * Validates captured tool-invocation lifecycle projection actions before recovery or commit.
 *
 * Illegal source/target transitions are refused before journaling. Complete-plan
 * uniqueness (one lifecycle update per durable invocation identity) is enforced by
 * CoordinationActionValidator::validatePlan across ordinary and after-turn actions.
 */
final readonly class ToolInvocationLifecycleCoordinationValidator implements CoordinationActionValidatorInterface
{
    public function supports(object $action): bool
    {
        return $action instanceof RecordToolInvocationLifecycleDTO;
    }

    public function validate(object $action): void
    {
        if (!$action instanceof RecordToolInvocationLifecycleDTO) {
            throw new \RuntimeException('Invalid prepared tool invocation lifecycle action.');
        }

        $correlation = $action->expectedCorrelation;
        if ('' === $correlation->deferredId
            || '' === $correlation->runId
            || '' === $correlation->stepId
            || '' === $correlation->toolCallId
            || $correlation->turnNo < 0) {
            throw new \RuntimeException('Invalid prepared tool invocation lifecycle identity.');
        }

        if (null !== $correlation->executionOwnerReference && null === $correlation->executionOwnerKind) {
            throw new \RuntimeException('Prepared tool invocation owner reference requires an owner kind.');
        }

        ToolInvocationLifecycleTransition::assertAllowed(
            $correlation->callerDisposition,
            $correlation->executionObservation,
            $action->callerDisposition,
            $action->executionObservation,
        );
    }
}
