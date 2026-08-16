<?php

declare(strict_types=1);

namespace Ineersa\Tui\Widget;

use Ineersa\Tui\Theme\TuiTheme;

/**
 * Rendering context for TuiWidget::render().
 *
 * Carries terminal dimensions, the active theme, and any future metadata
 * (color mode, font registry, etc.). Kept intentionally small and stable.
 */
final readonly class TuiRenderContext
{
    public function __construct(
        public int $terminalWidth,
        public TuiTheme $theme,
    ) {
    }
}
