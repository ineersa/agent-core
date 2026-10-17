<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/** Validates principal caller-disposition and execution-observation transitions. */
final class ToolInvocationLifecycleTransition
{
    public static function assertAllowed(
        ToolInvocationCallerDispositionEnum $fromDisposition,
        ToolInvocationExecutionObservationEnum $fromObservation,
        ToolInvocationCallerDispositionEnum $toDisposition,
        ToolInvocationExecutionObservationEnum $toObservation,
    ): void {
        if ($fromDisposition === $toDisposition && $fromObservation === $toObservation) {
            return;
        }

        $allowed = match ($fromDisposition->value.'|'.$fromObservation->value) {
            'foreground_pending|active' => [
                'foreground_result_accepted|terminal',
                'background_acknowledged|active',
            ],
            'background_acknowledged|active' => [
                'background_acknowledged|cancel_requested',
                'background_acknowledged|terminal',
            ],
            'background_acknowledged|cancel_requested' => [
                'background_acknowledged|terminal',
                'background_acknowledged|unknown',
            ],
            default => [],
        };

        $target = $toDisposition->value.'|'.$toObservation->value;
        if (!\in_array($target, $allowed, true)) {
            throw new \RuntimeException(\sprintf('Unsupported tool invocation lifecycle transition from %s/%s to %s/%s.', $fromDisposition->value, $fromObservation->value, $toDisposition->value, $toObservation->value));
        }
    }
}
