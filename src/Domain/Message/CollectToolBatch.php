<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

final readonly class CollectToolBatch extends AbstractAgentBusMessage
{
    /**
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
