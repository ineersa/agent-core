<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Cross-process cache-invalidation command for canonical events appended
 * outside run_control. Messenger routes it to the sole run_control consumer;
 * RunOrchestrator removes this run from ActiveRunContext, and the next state
 * transition rebuilds it from events.jsonl. It carries no event payload or
 * transition data because the canonical log is the recovery source.
 */
final readonly class InvalidateRunContext
{
    public function __construct(
        private string $runId,
    ) {
    }

    public function runId(): string
    {
        return $this->runId;
    }
}
