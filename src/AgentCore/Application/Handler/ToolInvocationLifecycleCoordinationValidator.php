<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;

/** Validates captured tool-invocation lifecycle projection actions before recovery or commit. */
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
    }
}
