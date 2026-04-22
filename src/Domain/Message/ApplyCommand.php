<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Bus message commanding the application of an external command (steer, cancel, etc.) to an active run step.
 */
final readonly class ApplyCommand extends AbstractAgentBusMessage
{
    /**
     * Initializes the command with run, turn, step, attempt, and idempotency context.
     *
     * @param array<string, mixed>      $payload
     * @param array{cancel_safe?: bool} $options
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $kind,
        public array $payload = [],
        public array $options = [],
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
