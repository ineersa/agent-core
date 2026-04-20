<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Defines the contract for domain messages dispatched via the agent bus, ensuring consistent metadata for execution tracking. It enforces the presence of unique identifiers and sequence numbers required for reliable event processing and state management.
 */
interface AgentBusMessageInterface
{
    public function runId(): string;

    public function turnNo(): int;

    public function stepId(): string;

    public function attempt(): int;

    public function idempotencyKey(): string;
}
