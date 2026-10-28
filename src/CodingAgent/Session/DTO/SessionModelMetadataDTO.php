<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\DTO;

final readonly class SessionModelMetadataDTO
{
    public function __construct(
        public ?string $model,
        public ?string $reasoning,
        public ?string $providerCacheKey,
    ) {
    }
}
