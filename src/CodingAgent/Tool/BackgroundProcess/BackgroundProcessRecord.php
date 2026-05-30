<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

/**
 * Read-only DTO representing a single background-process DB record.
 *
 * Used as the return type for list() and as the denormalization target
 * for BackgroundProcessRecordNormalizer.
 */
final readonly class BackgroundProcessRecord
{
    public function __construct(
        public int $id,
        public int $pid,
        public ?int $pgid,
        public string $command,
        public string $logPath,
        public string $startedAt,
        public ?string $finishedAt,
        public ?int $exitCode,
        public bool $stoppedByUser,
        public string $sessionId,
        public string $status,
    ) {
    }
}
