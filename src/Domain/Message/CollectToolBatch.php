<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * CollectToolBatch is a domain message representing a batch of tool executions within a specific agent run. It encapsulates execution context such as turn number, step ID, and attempt count to ensure proper sequencing and tracking. The class uses an idempotency key to prevent duplicate processing of the same batch.
 */
final readonly class CollectToolBatch extends AbstractAgentBusMessage
{
    /**
     * Initializes the batch with run context and idempotency key.
     *
     * @param list<string> $toolCallIds
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public array $toolCallIds,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
