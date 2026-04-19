<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Represents the immutable result of a single LLM agent step within a multi-turn conversation. It encapsulates execution metadata including run identifiers, turn sequence, and idempotency keys to ensure reliable processing. This value object serves as a core domain entity for tracking agent execution state.
 */
final readonly class LlmStepResult extends AbstractAgentBusMessage
{
    /**
     * Initializes LLM step result with run, turn, step, attempt, and idempotency metadata.
     *
     * @param array<string, mixed>|null $assistantMessage
     * @param array<string, int|float>  $usage
     * @param array<string, mixed>|null $error
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public ?array $assistantMessage = null,
        public array $usage = [],
        public ?string $stopReason = null,
        public ?array $error = null,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
