<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

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
