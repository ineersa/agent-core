<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

final readonly class ExecuteLlmStep extends AbstractAgentBusMessage
{
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $contextRef,
        public string $toolsRef,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
