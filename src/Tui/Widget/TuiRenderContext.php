<?php

declare(strict_types=1);

namespace Ineersa\Tui\Widget;

/**
 * Rendering context for TuiWidget::render().
 *
 * Carries terminal dimensions and any future metadata (theme, color mode, etc.).
 * Kept intentionally small and stable.
 */
final readonly class TuiRenderContext
{
    public function __construct(
        public int $terminalWidth = 80,
        public int $terminalHeight = 24,
    ) {
    }

    /**
     * Create a context with overridden width.
     */
    public function withWidth(int $width): self
    {
        return new self(terminalWidth: $width, terminalHeight: $this->terminalHeight);
    }

    /**
     * Create a context with overridden height.
     */
    public function withHeight(int $height): self
    {
        return new self(terminalWidth: $this->terminalWidth, terminalHeight: $height);
    }
}
