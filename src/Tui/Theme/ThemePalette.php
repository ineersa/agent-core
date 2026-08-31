<?php

declare(strict_types=1);

namespace Ineersa\Tui\Theme;

/**
 * Immutable palette value object mapping ThemeColorEnum tokens to CSS-ish color specs.
 *
 * Palette data is typically loaded from YAML theme files and supports
 * simple alias/variable resolution during construction.
 *
 * Color specs can be:
 *  - Hex strings like '#ff5500' or '#f50'
 *  - ANSI named colors like 'cyan', 'bright_green'
 *  - Empty string '' meaning "no color / default terminal foreground"
 *  - References to other ThemeColorEnum keys for alias support (resolved at load time)
 *
 * Users can change the palette later by loading a different theme YAML or
 * constructing a new palette programmatically.
 */
final readonly class ThemePalette
{
    /**
     * @param array<string, string> $colors ThemeColorEnum->value => color spec
     * @param string                $name   Human-readable theme name
     */
    public function __construct(
        public string $name,
        public array $colors = [],
    ) {
    }

    /**
     * Get the color spec for a token, or empty string if absent.
     */
    public function get(ThemeColorEnum $color): string
    {
        return $this->colors[$color->value] ?? '';
    }

    /**
     * Check whether a token has a non-empty color spec.
     */
    public function has(ThemeColorEnum $color): bool
    {
        return '' !== $this->get($color);
    }

    /**
     * Create a palette with overridden colors.
     *
     * Merges the given overrides on top of the current palette.
     * Useful for user customizations.
     *
     * @param array<string, string> $overrides
     */
    public function withOverrides(array $overrides): self
    {
        return new self(
            name: $this->name,
            colors: array_merge($this->colors, $overrides),
        );
    }

    /**
     * Create a palette from a raw associative array (e.g. parsed YAML).
     *
     * Resolves simple variable references where a color value is the name
     * of a var defined in the 'vars' section or another color token name.
     *
     * @param array{name?: string, vars?: array<string, string>, colors?: array<string, string>} $data
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? 'unnamed';
        $vars = $data['vars'] ?? [];
        $rawColors = $data['colors'] ?? [];

        $resolved = [];
        foreach ($rawColors as $key => $value) {
            $resolved[$key] = self::resolveColorSpec($value, $vars, $rawColors);
        }

        return new self(name: $name, colors: $resolved);
    }

    /**
     * Resolve a palette entry: empty string, vars section, or another color token key.
     *
     * @param array<string, string> $vars
     * @param array<string, string> $rawColors
     */
    private static function resolveColorSpec(string $value, array $vars, array $rawColors): string
    {
        if ('' === $value) {
            return '';
        }

        if (isset($vars[$value])) {
            return $vars[$value];
        }

        if (isset($rawColors[$value])) {
            $target = $rawColors[$value];
            if ($target === $value) {
                return $value;
            }

            return self::resolveColorSpec($target, $vars, $rawColors);
        }

        return $value;
    }
}
