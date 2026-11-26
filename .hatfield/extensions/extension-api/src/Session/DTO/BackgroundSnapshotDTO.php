<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\DTO;

/** Null counts or schedules mean that inventory is unavailable. */
final readonly class BackgroundSnapshotDTO
{
    /** @param list<ScheduleDTO>|null $schedules */
    public function __construct(
        public string $sessionId,
        public ?int $activeSubagents,
        public ?int $backgroundScripts,
        public ?array $schedules = null,
    ) {
    }
}
