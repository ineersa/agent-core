<?php

declare(strict_types=1);

namespace Ineersa\Tui\Theme;

/**
 * Provenance for a theme palette registered in {@see ThemeRegistry}.
 */
final readonly class ThemeLoadedEntryDTO
{
    public function __construct(
        public string $name,
        public string $sourcePath,
        public bool $userSource,
    ) {
    }
}
