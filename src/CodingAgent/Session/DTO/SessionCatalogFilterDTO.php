<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\DTO;

final readonly class SessionCatalogFilterDTO
{
    public function __construct(
        public ?string $since,
        public ?string $until,
        public ?string $query,
        public ?string $cwd,
        public int $limit,
        public int $offset,
    ) {
    }
}
