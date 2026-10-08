<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Immutable execution envelope for one LLM turn.
 *
 * The coordinator supplies the complete immutable invocation context. This
 * request travels on the execution bus with its original turn and step identity.
 * Workers must never load a RunState snapshot to rebuild it.
 */
final readonly class ExecuteLlmStep extends AbstractAgentBusMessage
{
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $toolsRef,
        /** @var list<AgentMessage> */
        public array $messages = [],
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
