<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * One loaded resource entry for TUI startup display.
 */
final readonly class LoadedResourceItemDTO
{
    public function __construct(
        public string $name,
        public string $sourcePath = '',
        public bool $disabled = false,
    ) {
    }
}
