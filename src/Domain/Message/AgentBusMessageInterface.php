<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Defines the contract for domain messages dispatched via the agent bus, ensuring consistent metadata for execution tracking. It enforces the presence of unique identifiers and sequence numbers required for reliable event processing and state management.
 */
interface AgentBusMessageInterface
{
    /**
     * Returns the unique identifier for the current agent run.
     */
    public function runId(): string;

    /**
     * Returns the sequential turn number within the agent run.
     */
    public function turnNo(): int;

    /**
     * Returns the unique identifier for the specific execution step.
     */
    public function stepId(): string;

    /**
     * Returns the current retry attempt count for the operation.
     */
    public function attempt(): int;

    /**
     * Returns the unique key used to ensure idempotent message processing.
     */
    public function idempotencyKey(): string;
}
