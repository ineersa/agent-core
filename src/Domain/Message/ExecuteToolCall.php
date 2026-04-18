<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

final readonly class ExecuteToolCall extends AbstractAgentBusMessage
{
    /**
     * @param array<string, mixed> $args
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $toolCallId,
        public string $toolName,
        public array $args,
        public int $orderIndex,
        public ?string $toolIdempotencyKey = null,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
