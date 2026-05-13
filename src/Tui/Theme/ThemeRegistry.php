<?php

declare(strict_types=1);

namespace Ineersa\Tui\Theme;

/**
 * Registry of built-in and user-loaded TUI themes.
 *
 * Provides theme lookup by name and a default fallback.
 * The built-in themes are loaded from YAML files under config/themes/.
 *
 * Users can override the default theme via config.
 */
final class ThemeRegistry
{
    /** @var array<string, ThemePalette> */
    private array $themes = [];

    /**
     * @param list<ThemePalette> $builtin     Built-in theme palettes
     * @param string             $defaultName Default theme to use when lookup fails
     */
    public function __construct(
        array $builtin = [],
        private readonly string $defaultName = 'cyberpunk',
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
     * Get the palette for the given name, or the default if not found.
     */
    public function getOrDefault(string $name): ThemePalette
    {
        return $this->themes[$name] ?? $this->getDefault();
    }

    /**
     * Get the default theme palette.
     */
    public function getDefault(): ThemePalette
    {
        return $this->themes[$this->defaultName]
            ?? throw new \RuntimeException("Default theme '{$this->defaultName}' not registered.");
    }

    /**
     * Get the default theme name.
     */
    public function getDefaultName(): string
    {
        return $this->defaultName;
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
