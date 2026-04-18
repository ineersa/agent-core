<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

final readonly class ExecuteToolCall extends AbstractAgentBusMessage
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $toolCallId,
        public string $toolName,
        public array $arguments,
        public int $orderIndex,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
