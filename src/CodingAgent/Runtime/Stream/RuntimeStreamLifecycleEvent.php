<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

/**
 * Event dispatched for stream lifecycle transitions (start/end/error).
 *
 * Subscribers that need per-stream state reset register for
 * the fixed event names defined in LlmStreamDispatchObserver.
 */
final class RuntimeStreamLifecycleEvent
{
    public function __construct(
        public readonly string $runId,
        public readonly ?string $stepId,
        public readonly ?\Throwable $error = null,
    ) {
    }
}
