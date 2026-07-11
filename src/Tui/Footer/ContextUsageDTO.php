<?php

declare(strict_types=1);

namespace Ineersa\Tui\Footer;

use Ineersa\Tui\Theme\ThemeColorEnum;

/**
 * Formatted context-window usage for footer, picker, and transcript surfaces.
 */
final readonly class ContextUsageDTO
{
    public function __construct(
        public string $text,
        public ThemeColorEnum $color,
    ) {
    }
}
