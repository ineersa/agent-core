<?php

declare(strict_types=1);

namespace Ineersa\Tui\Widget;

use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Theme\TuiTheme;

/**
 * Rendering context for TuiWidget::render().
 *
 * Carries terminal dimensions, the active theme, and any future metadata
 * (color mode, font registry, etc.). Kept intentionally small and stable.
 */
final readonly class TuiRenderContext
{
    /**
     * Convenience default used only by unit tests;
     * production code must always supply the real theme.
     */
    public function __construct(
        public int $terminalWidth = 80,
        public int $terminalHeight = 24,
        public TuiTheme $theme = new DefaultTheme(
            new ThemePalette(name: '__test_context__', colors: []),
        ),
    ) {
    }

    /**
     * Create a context with overridden width.
     */
    public function withWidth(int $width): self
    {
        return new self(
            terminalWidth: $width,
            terminalHeight: $this->terminalHeight,
            theme: $this->theme,
        );
    }

    /**
     * Create a context with overridden height.
     */
    public function withHeight(int $height): self
    {
        return new self(
            terminalWidth: $this->terminalWidth,
            terminalHeight: $height,
            theme: $this->theme,
        );
    }

    /**
     * Create a context with a different theme.
     */
    public function withTheme(TuiTheme $theme): self
    {
        return new self(
            terminalWidth: $this->terminalWidth,
            terminalHeight: $this->terminalHeight,
            theme: $theme,
        );
    }
}
