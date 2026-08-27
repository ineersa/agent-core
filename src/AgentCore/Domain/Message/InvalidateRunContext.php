<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Internal run-control notification that canonical event persistence invalidated
 * a process-local active context. It deliberately carries no transition data.
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
