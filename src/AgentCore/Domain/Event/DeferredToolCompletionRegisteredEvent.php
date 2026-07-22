<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;

/**
 * Dispatched after a deferred ExecuteToolCall correlation is durably registered (or rediscovered on retry).
 */
final readonly class DeferredToolCompletionRegisteredEvent
{
    public function __construct(
        public DeferredToolCompletionCorrelation $correlation,
    ) {
    }
}
