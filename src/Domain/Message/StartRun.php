<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * A domain event representing the initiation of a specific execution run within the agent core. It encapsulates the unique identifiers and sequence metadata required to track the lifecycle of a single run instance. This class serves as a value object for conveying run start context across domain boundaries.
 */
final readonly class StartRun extends AbstractAgentBusMessage
{
    /**
     * Initializes the run start event with run ID, turn number, step ID, attempt count, and idempotency key.
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
