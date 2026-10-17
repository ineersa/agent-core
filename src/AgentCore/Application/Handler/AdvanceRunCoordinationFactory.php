<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;

/** Captures the continuation identity before coordination dispatch. */
final class AdvanceRunCoordinationFactory
{
    public static function forCompletion(\Ineersa\AgentCore\Domain\Run\RunState $state, \Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO $fence, bool $mailboxDrain = false, ?\Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO $appliedCompletion = null): DispatchCoordinationMessageDTO
    {
        // Arrivals in one idle turn share a token. A metadata-only bounded
        // drain advances its cursor so a parked prefix cannot strand the tail.
        $step = implode('|', ['completion', $state->turnNo, $fence->wakeGeneration, $fence->historyGeneration, $fence->controllerGeneration, $mailboxDrain ? 'drain:'.$state->lastSeq : 'arrival:'.($state->lastAppliedAdvanceKey ?? 'initial')]);

        return new DispatchCoordinationMessageDTO(new AdvanceRun(
            runId: $state->runId,
            turnNo: $state->turnNo,
            stepId: $step,
            attempt: 1,
            idempotencyKey: hash('sha256', $state->runId.'|'.$step),
            completionFence: $fence,
            completionMailboxDrain: $mailboxDrain,
            appliedCompletion: $appliedCompletion,
        ));
    }

    public static function create(string $runId, int $turnNo, string $prefix): DispatchCoordinationMessageDTO
    {
        $stepId = \sprintf('%s-%d', $prefix, hrtime(true));

        return new DispatchCoordinationMessageDTO(new AdvanceRun(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $stepId)),
        ));
    }
}
