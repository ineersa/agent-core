<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/** Bounded notification; the original result remains in its private immutable file. */
final readonly class DurableExecutionResult extends AbstractAgentBusMessage implements RunControlTransitionMessageInterface
{
    public function __construct(string $runId, int $turnNo, string $stepId, int $attempt, string $idempotencyKey, public string $effectId, public string $claimToken, public string $sha256, public int $bytes, public string $resultType)
    {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
