<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Bus envelope fields shared by execution messages.
 */
abstract readonly class AbstractAgentBusMessage
{
    public function __construct(
        private string $runId,
        private int $turnNo,
        private string $stepId,
        private int $attempt,
        private string $idempotencyKey,
    ) {
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function turnNo(): int
    {
        return $this->turnNo;
    }

    public function stepId(): string
    {
        return $this->stepId;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }
}
