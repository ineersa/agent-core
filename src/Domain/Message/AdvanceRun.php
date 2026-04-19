<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Represents a domain event signaling the progression of a specific run step within an agent execution. It encapsulates the unique identifiers for the run, turn, and step, along with attempt tracking and idempotency guarantees. This immutable value object ensures consistent state representation for run advancement logic.
 */
final readonly class AdvanceRun extends AbstractAgentBusMessage
{
    /**
     * Initializes the advance run event with run, turn, step, attempt, and idempotency identifiers.
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public array $payload = [],
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
