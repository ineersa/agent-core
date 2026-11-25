<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui\DTO;

final readonly class TuiBackgroundSnapshotDTO
{
    /** @param list<TuiScheduleDTO>|null $schedules */
    public function __construct(
        public string $sessionId,
        public ?int $activeSubagents,
        public ?int $backgroundScripts,
        public ?array $schedules = null,
    ) {
    }
}
