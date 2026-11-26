<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\DTO;

final readonly class ScheduleDTO
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $recurring,
        public ?\DateTimeImmutable $nextRunAt,
    ) {
    }
}
