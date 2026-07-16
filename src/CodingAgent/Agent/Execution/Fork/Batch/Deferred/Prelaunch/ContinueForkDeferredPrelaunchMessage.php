<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch;

/**
 * Resume fork deferred child launch after canonical compaction terminal on fork-local copy.
 */
final readonly class ContinueForkDeferredPrelaunchMessage
{
    public function __construct(
        public string $batchLifecycleId,
        public string $forkLocalRunId,
        public string $terminalEventType,
        /** @var array<string, mixed> */
        public array $terminalPayload = [],
    ) {
    }
}
