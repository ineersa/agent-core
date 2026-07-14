<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;

/**
 * Durable run_control wakeup to apply a synthetic batch interruption (timeout or parent cancel).
 */
final readonly class InterruptDeferredSubagentBatchMessage
{
    public function __construct(
        public string $batchLifecycleId,
        public DeferredSubagentInterruptionKindEnum $kind,
    ) {
    }
}
