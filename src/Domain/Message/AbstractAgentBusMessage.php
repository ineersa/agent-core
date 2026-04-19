<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * AbstractAgentBusMessage serves as a base value object for agent execution events, encapsulating core execution context such as run identifiers, turn numbers, and step metadata. It provides immutable accessors for these fields to ensure consistent data structure across agent bus messages. The class enforces immutability through readonly properties and final getter methods.
 */
abstract readonly class AbstractAgentBusMessage implements AgentBusMessageInterface
{
    /**
     * Initializes the message with run, turn, step, attempt, and idempotency context.
     */
    public function __construct(
        private string $runId,
        private int $turnNo,
        private string $stepId,
        private int $attempt,
        private string $idempotencyKey,
    ) {
    }

    /**
     * Returns the unique identifier for the agent run.
     */
    public function runId(): string
    {
        return $this->runId;
    }

    /**
     * Returns the sequential turn number within the agent run.
     */
    public function turnNo(): int
    {
        return $this->turnNo;
    }

    /**
     * Returns the unique identifier for the current execution step.
     */
    public function stepId(): string
    {
        return $this->stepId;
    }

    /**
     * Returns the current attempt count for the step execution.
     */
    public function attempt(): int
    {
        return $this->attempt;
    }

    /**
     * Returns the idempotency key to prevent duplicate processing.
     */
    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }
}
