<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\DTO;

final readonly class BackgroundProcessDTO
{
    public function __construct(
        public int $id,
        public int $pid,
        public string $command,
        public \DateTimeImmutable $startedAt,
        public string $status,
    ) {
    }
}
