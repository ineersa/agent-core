<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * The ApplyCommand class represents a domain event for applying a command within a specific execution step. It encapsulates the necessary context such as run ID, turn number, and step ID to ensure proper sequencing and identification of the operation.
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
