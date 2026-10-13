<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

use Symfony\Component\Lock\LockInterface;

/** Close the producer descriptor without unlocking descriptors inherited by writers. */
final readonly class ManagedBackgroundProcessLaunchDTO
{
    public function __construct(
        public string $pidPath,
        public string $logPath,
        public string $statusPath,
        public LockInterface $writerLease,
    ) {
    }
}
