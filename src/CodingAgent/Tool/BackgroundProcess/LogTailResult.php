<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

/**
 * Read-only DTO returned by BackgroundProcessManager::readLogTail().
 */
final readonly class LogTailResult
{
    public function __construct(
        public int $pid,
        public string $logPath,
        public string $content,
        public bool $truncated,
        public int $totalBytes,
    ) {
    }
}
