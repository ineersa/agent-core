<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

final readonly class ToolCallResult extends AbstractAgentBusMessage
{
    /**
     * Initializes tool call result with run, turn, step, attempt, and idempotency metadata.
     *
     * @param array<string, mixed>|null $error
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $toolCallId,
        public int $orderIndex,
        public mixed $result = null,
        public bool $isError = false,
        public ?array $error = null,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
