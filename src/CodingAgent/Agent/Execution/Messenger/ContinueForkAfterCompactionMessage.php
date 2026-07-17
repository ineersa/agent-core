<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Messenger;

/**
 * Continues a reserved fork batch after fork-local canonical compaction terminal.
 */
final readonly class ContinueForkAfterCompactionMessage
{
    public function __construct(
        public string $forkLocalRunId,
        public bool $success,
        public ?string $failureReason = null,
    ) {
    }
}
