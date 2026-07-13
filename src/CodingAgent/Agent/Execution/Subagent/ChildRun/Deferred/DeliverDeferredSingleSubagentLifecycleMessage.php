<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

/**
 * Durable wake-up to emit parent subagent_progress and/or complete deferred single-child tool execution.
 */
final readonly class DeliverDeferredSingleSubagentLifecycleMessage
{
    public function __construct(
        public string $lifecycleId,
    ) {
    }
}
