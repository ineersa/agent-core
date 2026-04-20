<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * AbstractAgentBusMessage serves as a base value object for agent execution events, encapsulating core execution context such as run identifiers, turn numbers, and step metadata. It provides immutable accessors for these fields to ensure consistent data structure across agent bus messages. The class enforces immutability through readonly properties and final getter methods.
 */
abstract readonly class AbstractAgentBusMessage implements AgentBusMessageInterface
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
