<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

/**
 * Durable run_control wakeup to replay child events.jsonl from the stored cursor (gap/restart only).
 */
final readonly class RecoverDeferredSingleSubagentLifecycleMessage
{
    public function __construct(
        public string $lifecycleId,
    ) {
    }
}
