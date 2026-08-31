<?php

declare(strict_types=1);

namespace Ineersa\Tui\Theme;

/**
 * Terminal UI theme contract.
 *
 * Applies semantic styling tokens to text using ANSI escape codes.
 * Each implementation maps {@see ThemeColorEnum} values to terminal formatting.
 *
 * @see DefaultTheme for the Symfony TUI Style-backed implementation
 * @see ThemePalette for the pure-data palette value object
 */
interface TuiTheme
{
    /**
     * Human-readable theme name from the resolved theme palette.
     */
    public function name(): string;

    /**
     * Apply a semantic color to the given text.
     *
     * Returns ANSI-wrapped text ready for terminal display.
     * If no style is defined for the color, returns the text unmodified.
     *
     * @return string ANSI-styled text
     */
    public function color(ThemeColorEnum $color, string $text): string;

    /* ───────── Convenience aliases ───────── */

    public function accent(string $text): string;

    public function text(string $text): string;

    public function muted(string $text): string;

    public function success(string $text): string;

    public function warning(string $text): string;

    public function error(string $text): string;

    /**
     * Get the underlying palette for introspection and direct color resolution.
     *
     * Used by callers that need a raw color spec (e.g. for constructing
     * Symfony TUI Style objects) rather than ANSI-wrapped text.
     */
    public function getPalette(): ThemePalette;
}
