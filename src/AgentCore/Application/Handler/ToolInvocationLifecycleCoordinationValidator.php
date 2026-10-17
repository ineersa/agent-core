<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;

/**
 * Validates captured tool-invocation lifecycle projection actions before recovery or commit.
 *
 * Per-action source/target transition checks happen here before journaling.
 * Complete-plan uniqueness for one store identity (`run_id`, `tool_call_id`) is
 * enforced by CoordinationActionValidator::validatePlan across ordinary and
 * after-turn actions. Exact-target replay no-ops do not authorize duplicate
 * lifecycle actions inside one prepared plan.
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

        if ((null === $action->acceptedCompletionId) !== (null === $action->acceptedResultFingerprint)) {
            throw new \RuntimeException('Prepared terminal acceptance requires completion id and result fingerprint together.');
        }
        if (null !== $action->acceptedCompletionId
            && ToolInvocationExecutionObservationEnum::Terminal !== $action->executionObservation
            && ToolInvocationExecutionObservationEnum::Unknown !== $action->executionObservation) {
            throw new \RuntimeException('Prepared terminal acceptance requires a terminal or unknown execution observation.');
        }

        // Reuse the complete domain receipt/transition rules before journaling.
        // withLifecycle rejects incorrect deterministic IDs and conflicting accepted
        // fingerprints; projection must not be the first place those fail.
        try {
            $correlation->withLifecycle(
                $action->callerDisposition,
                $action->executionObservation,
                $action->acceptedCompletionId,
                $action->acceptedResultFingerprint,
            );
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }
    }
}
