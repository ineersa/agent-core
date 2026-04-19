<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Represents a domain event for executing an LLM step within an agent run, capturing execution context such as run ID, turn number, and step identifier. It includes retry attempt tracking and an idempotency key to ensure safe re-execution. This class serves as a structured payload for triggering or recording LLM processing actions.
 */
final readonly class ExecuteLlmStep extends AbstractAgentBusMessage
{
    /**
     * Initializes the LLM step execution context with run, turn, step, attempt, and idempotency details.
     */
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
