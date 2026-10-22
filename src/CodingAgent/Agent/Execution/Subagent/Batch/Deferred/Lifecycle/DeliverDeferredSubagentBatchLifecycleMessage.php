<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle;

/**
 * Enqueues progress and terminal completion for one deferred child invocation.
 */
final readonly class DeliverDeferredSubagentBatchLifecycleMessage
{
    public function __construct(
        public string $batchLifecycleId,
    ) {
    }
}
