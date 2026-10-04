<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/** Durable recovery notice, not an execution result. */
final readonly class ExecutionOutcomeUnknown extends AbstractAgentBusMessage implements RunControlTransitionMessageInterface
{
    public const string ERROR_MESSAGE = 'Execution outcome is unknown: its worker stopped before a durable result was recorded. Side effects may already have occurred. Automatic execution is blocked; use /repair to inspect the run before another attempt.';

    public function __construct(string $runId, int $turnNo, string $stepId, int $attempt, string $idempotencyKey, public string $effectId, public string $claimToken)
    {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
