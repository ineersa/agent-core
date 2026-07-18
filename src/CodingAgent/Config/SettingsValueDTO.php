<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Result of querying a single dotted settings path against a fresh resolution.
 */
final readonly class SettingsValueDTO
{
    public function __construct(
        public bool $exists,
        public mixed $value = null,
        public ?SettingsLayerEnum $layer = null,
        public bool $composite = false,
    ) {
    }
}
