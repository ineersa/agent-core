<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

/**
 * Read-only DTO returned by BackgroundProcessManager::start().
 */
final readonly class StartResult
{
    public function __construct(
        public int $id,
        public int $pid,
        public string $logPath,
    ) {
    }
}
