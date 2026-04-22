<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Bus message commanding the collection and dispatch of a batch of pending tool calls for parallel or sequential execution.
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
