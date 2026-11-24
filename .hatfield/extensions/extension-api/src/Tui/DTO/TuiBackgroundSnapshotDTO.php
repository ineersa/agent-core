<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui\DTO;

final readonly class TuiBackgroundSnapshotDTO
{
    public function __construct(
        public string $sessionId,
        public ?int $activeSubagents,
        public ?int $backgroundScripts,
    ) {
    }
}
