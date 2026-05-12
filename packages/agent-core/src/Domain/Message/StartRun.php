<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

final readonly class StartRun extends AbstractAgentBusMessage
{
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public StartRunPayload $payload,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
