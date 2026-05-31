<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

/**
 * Read-only DTO representing a single background-process DB record.
 *
 * Hydrated by Symfony's built-in ObjectNormalizer via DenormalizerInterface.
 * DB snake_case columns are mapped to camelCase properties via a
 * CamelCaseToSnakeCaseNameConverter on the ObjectNormalizer.
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
