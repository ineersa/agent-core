<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

/**
 * Read-only DTO returned by BackgroundProcessManager::stop().
 */
final readonly class StopResult
{
    public function __construct(
        public int $pid,
        public ?int $pgid,
        public bool $stoppedByUser,
        public bool $alreadyFinished,
        public string $signalSent,
    ) {
    }
}
