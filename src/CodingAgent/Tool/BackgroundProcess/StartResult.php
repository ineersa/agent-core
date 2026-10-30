<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

/**
 * Native launch handle returned by BackgroundProcessManager::start().
 *
 * statusPath uniquely identifies the native process for local supervision.
 * id is present only when this process inserted the durable row (in-process).
 * Owner-backed workers publish Started and may observe id later; they must not
 * invent a durable id to satisfy callers.
 */
final readonly class StartResult
{
    public function __construct(
        public int $pid,
        public string $logPath,
        public string $statusPath,
        public ?int $id = null,
    ) {
    }
}
