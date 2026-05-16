<?php

declare(strict_types=1);

namespace Ineersa\Tui\Theme;

/**
 * Registry of built-in and user-loaded TUI themes.
 *
 * Provides theme lookup by name. The default theme is driven by
 * application config ({@see config/hatfield.defaults.yaml}) — this
 * registry itself carries no opinion about which theme is "default".
 *
 * The built-in themes are loaded from YAML files under config/themes/.
 */
final class ThemeRegistry
{
    /** @var array<string, ThemePalette> */
    private array $themes = [];

    /**
     * @param list<ThemePalette> $builtin Built-in theme palettes
     */
    public function __construct(
        array $builtin = [],
    ) {
        foreach ($builtin as $palette) {
            $this->register($palette);
        }
    }

    /**
     * Register a palette in the registry.
     */
    public function register(ThemePalette $palette): void
    {
        $this->themes[$palette->name] = $palette;
    }

    /**
     * Look up a palette by name.
     *
     * Returns null if no palette with the given name is registered.
     */
    public function get(string $name): ?ThemePalette
    {
        return $this->themes[$name] ?? null;
    }

    /**
     * Look up a palette by name, throwing if not found.
     *
     * Used by the theme factory when the name is driven by config
     * and a missing theme is a configuration error.
     *
     * @throws \RuntimeException if the theme is not registered
     */
    public function getOrThrow(string $name): ThemePalette
    {
        $palette = $this->themes[$name] ?? null;
        if (null === $palette) {
            $allNames = $this->getNames();
            $names = [] !== $allNames ? implode(', ', $allNames) : '(none)';

            throw new \RuntimeException(\sprintf('Theme "%s" is not registered. Available themes: %s.', $name, $names));
        }

        return $palette;
    }

    /**
     * Return all registered theme names.
     *
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_keys($this->themes);
    }

    /**
     * Check whether a named theme exists.
     */
    public function has(string $name): bool
    {
        return isset($this->themes[$name]);
    }
}
