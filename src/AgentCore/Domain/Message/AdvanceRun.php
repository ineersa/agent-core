<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

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
