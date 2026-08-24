<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Immutable execution envelope for one LLM turn.
 *
 * The provider resolves the current session model and reasoning at invocation
 * time. Scheduling must not snapshot mutable session selection here.
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
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
