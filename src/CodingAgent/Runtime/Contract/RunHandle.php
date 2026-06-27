<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Lightweight handle returned when a run is started or attached.
 */
final readonly class RunHandle
{
    public function __construct(
        public string $runId,
        public string $status = 'starting',
    ) {
    }
}
