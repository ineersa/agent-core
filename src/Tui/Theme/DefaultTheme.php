<?php

declare(strict_types=1);

namespace Ineersa\Tui\Theme;

use Symfony\Component\Tui\Style\Style;

/**
 * Default theme implementation backed by Symfony TUI styling primitives.
 *
 * Builds a per-color Style cache on first use for efficient lookup.
 * Falls back to unstyled text when a color token has no palette entry.
 *
 * Palette data flows through {@see ThemePalette} (loaded from YAML),
 * which maps {@see ThemeColor} enum values to CSS-ish color specs.
 * This class translates those specs to ANSI via {@see Style::apply()}.
 */
final class DefaultTheme implements TuiTheme
{
    /** @var array<string, Style> Cache of built Style objects keyed by ThemeColor->value */
    private array $styleCache = [];

    public function __construct(
        private readonly ThemePalette $palette,
    ) {
    }

    public function name(): string
    {
        return $this->palette->name;
    }

    public function color(ThemeColor $color, string $text): string
    {
        return $this->buildStyleFor($color)->apply($text);
    }

    /* ───────── Convenience aliases ───────── */

    public function accent(string $text): string
    {
        return $this->color(ThemeColor::Accent, $text);
    }

    public function text(string $text): string
    {
        return $this->color(ThemeColor::Text, $text);
    }

    public function muted(string $text): string
    {
        return $this->color(ThemeColor::Muted, $text);
    }

    public function success(string $text): string
    {
        return $this->color(ThemeColor::Success, $text);
    }

    public function warning(string $text): string
    {
        return $this->color(ThemeColor::Warning, $text);
    }

    public function error(string $text): string
    {
        return $this->color(ThemeColor::Error, $text);
    }

    /**
     * Get the underlying palette for introspection.
     */
    public function getPalette(): ThemePalette
    {
        return $this->palette;
    }

    /* ───────── Internal ───────── */

    /**
     * Build (or retrieve from cache) a Style for a given ThemeColor.
     */
    private function buildStyleFor(ThemeColor $color): Style
    {
        $key = $color->value;

        if (isset($this->styleCache[$key])) {
            return $this->styleCache[$key];
        }

        $spec = $this->palette->get($color);

        if ('' === $spec) {
            return $this->styleCache[$key] = new Style();
        }

        try {
            $this->styleCache[$key] = new Style(color: $spec);
        } catch (\Throwable) {
            // If the color spec is invalid, fall back to plain style
            return $this->styleCache[$key] = new Style();
        }

        return $this->styleCache[$key];
    }
}
