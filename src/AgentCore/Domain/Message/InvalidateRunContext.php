<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Cross-process hot-cache invalidation for canonical events appended outside
 * run_control. Side-writers publish the shared disposable RunState projection
 * before dispatching this command. Messenger routes it to the sole run_control
 * consumer; RunOrchestrator drops only the process-local hot cache so the next
 * transition reloads the already-published shared state. It carries no event
 * payload because the shared projection is the ordinary source.
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
