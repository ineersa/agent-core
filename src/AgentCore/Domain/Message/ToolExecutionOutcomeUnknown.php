<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/** Batch-backed confirmed-dead execution notice, never a fabricated tool result. */
final readonly class ToolExecutionOutcomeUnknown extends AbstractAgentBusMessage implements RunControlTransitionMessageInterface
{
    public function __construct(string $runId, int $turnNo, string $stepId, int $attempt, string $idempotencyKey, public string $toolCallId, public string $authorizationId, public string $claimToken)
    {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
