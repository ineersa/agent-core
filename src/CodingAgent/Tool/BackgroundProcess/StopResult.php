<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

/**
 * Read-only DTO returned by BackgroundProcessManager::stop().
 */
final readonly class StopResult
{
    public function __construct(
        public ?int $pgid,
        public bool $alreadyFinished,
        public string $signalSent,
    ) {
    }
}
