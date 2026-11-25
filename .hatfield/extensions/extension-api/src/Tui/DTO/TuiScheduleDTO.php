<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui\DTO;

final readonly class TuiScheduleDTO
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $recurring,
        public ?\DateTimeImmutable $nextRunAt,
    ) {
    }
}
