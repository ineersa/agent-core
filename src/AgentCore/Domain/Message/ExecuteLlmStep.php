<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Immutable execution envelope for one LLM turn.
 *
 * The required {@see $model} is resolved at scheduling time and must be the
 * only model identity the worker uses for provider I/O. Workers must not
 * re-resolve from mutable session/default state.
 */
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
        public string $model,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);

        if ('' === trim($this->model)) {
            throw new \InvalidArgumentException('ExecuteLlmStep requires a non-empty model reference.');
        }
    }
}
