<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Represents a domain event signaling the execution of a tool call within an agent run. It encapsulates the necessary context such as run identifiers, turn sequence, and idempotency keys to ensure reliable processing. This class serves as a structured payload for internal event bus communication.
 */
final readonly class ExecuteToolCall extends AbstractAgentBusMessage
{
    /**
     * Initializes the tool call execution event with run, turn, step, attempt, and idempotency context.
     *
     * @param array<string, mixed>      $args
     * @param array<string, mixed>|null $assistantMessage
     * @param array<string, mixed>|null $argSchema
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
        public ?string $mode = null,
        public ?int $timeoutSeconds = null,
        public ?int $maxParallelism = null,
        public ?array $assistantMessage = null,
        public ?array $argSchema = null,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
