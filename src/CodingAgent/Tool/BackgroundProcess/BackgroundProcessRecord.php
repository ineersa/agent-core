<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

/**
 * Read-only DTO representing a single background-process DB record.
 *
 * Created by BackgroundProcess::toRecord() from the Doctrine ORM entity.
 * No custom serializer/normalizer is used for DB row hydration —
 * schema is managed by Doctrine migrations.
 */
final readonly class BackgroundProcessRecord
{
    public function __construct(
        public int $id = 0,
        public int $pid = 0,
        public ?int $pgid = null,
        public string $command = '',
        public string $logPath = '',
        public string $startedAt = '',
        public ?string $finishedAt = null,
        public ?int $exitCode = null,
        public bool $stoppedByUser = false,
        public string $sessionId = '',
        public string $status = '',
    ) {
    }
}
