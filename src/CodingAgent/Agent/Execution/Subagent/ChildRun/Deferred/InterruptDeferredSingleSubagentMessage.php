<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

/**
 * Durable run_control wakeup to apply a synthetic single-child interruption (timeout or parent cancel).
 */
final readonly class InterruptDeferredSingleSubagentMessage
{
    public function __construct(
        public string $lifecycleId,
        public DeferredSingleSubagentInterruptionKindEnum $kind,
    ) {
    }
}
