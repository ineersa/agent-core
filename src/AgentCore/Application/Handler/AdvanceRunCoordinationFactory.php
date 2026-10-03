<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;

/** Captures the continuation identity before coordination dispatch. */
final class AdvanceRunCoordinationFactory
{
    public static function create(string $runId, int $turnNo, string $prefix, string $errorMessage): DispatchCoordinationMessageDTO
    {
        $stepId = \sprintf('%s-%d', $prefix, hrtime(true));

        return new DispatchCoordinationMessageDTO(new AdvanceRun(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $stepId)),
        ), $errorMessage);
    }
}
