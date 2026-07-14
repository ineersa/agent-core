<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

/**
 * Durable run_control wakeup to replay child events.jsonl tails for every batch child (gap/restart only).
 */
final readonly class RecoverDeferredSubagentBatchLifecycleMessage
{
    public function __construct(
        public string $batchLifecycleId,
    ) {
    }
}
