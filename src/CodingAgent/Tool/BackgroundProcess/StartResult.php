<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

/**
 * Native launch handle returned by BackgroundProcessManager::start().
 *
 * statusPath uniquely identifies the native process for local supervision.
 * id is the owner-prepared durable row when present; never a fabricated zero.
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
