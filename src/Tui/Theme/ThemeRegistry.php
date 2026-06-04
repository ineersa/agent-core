<?php

declare(strict_types=1);

namespace Ineersa\Tui\Theme;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Registry of built-in and user-loaded TUI themes.
 *
 * Self-loads palettes from config/hatfield theme paths at construction.
 * User-configured theme paths override built-in themes of the same name.
 *
 * The default theme name is driven by application config
 * ({@see config/hatfield.defaults.yaml}) — this registry itself carries
 * no opinion about which theme is "default".
 */
final class ThemeRegistry
{
    /** @var array<string, ThemePalette> */
    private array $themes = [];

    public function __construct(
        AppConfig $appConfig,
        AppResourceLocator $resources,
        private readonly LoggerInterface $logger,
    ) {
        $tuiConfig = $appConfig->tui;

        // Load user-configured theme paths first (higher priority — they
        // override built-in themes with the same name).
        foreach ($tuiConfig->themePaths as $path) {
            foreach ($this->loadDirectory($path) as $palette) {
                if (!isset($this->themes[$palette->name])) {
                    $this->themes[$palette->name] = $palette;
                }
            }
        }

        // Load built-in themes second (lower priority — only fills gaps).
        $builtinPath = $resources->getBuiltinThemesPath();
        foreach ($this->loadDirectory($builtinPath) as $palette) {
            if (!isset($this->themes[$palette->name])) {
                $this->themes[$palette->name] = $palette;
            }
        }
    }

    /**
     * Register a palette in the registry (runtime additions only).
     *
     * Registration at construction time is handled by the constructor
     * loading from Hatfield theme paths. This method exists for
     * programmatic registration post-construction — e.g. when a test
     * or extension wants to add a palette without writing a YAML file.
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
        $names = array_keys($this->themes);
        sort($names);

        return $names;
    }

    /**
     * Check whether a named theme exists.
     */
    public function has(string $name): bool
    {
        return isset($this->themes[$name]);
    }

    /**
     * Load a palette from a YAML file path.
     *
     * @throws \RuntimeException if the file is not readable or parseable
     */
    private function loadFile(string $path): ThemePalette
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("Theme file not readable: {$path}");
        }

        $yaml = file_get_contents($path);
        if (false === $yaml) {
            throw new \RuntimeException("Failed to read theme file: {$path}");
        }

        $data = Yaml::parse($yaml);
        if (!\is_array($data)) {
            throw new \RuntimeException("Invalid YAML structure in theme file: {$path}");
        }

        return ThemePalette::fromArray($data);
    }

    /**
     * Load all YAML theme files from a directory.
     *
     * Scans for *.yaml and *.yml files (non-recursive).
     * Uses scandir() instead of glob() because glob() does not
     * support phar:// stream wrappers (PHAR mode).
     *
     * @return list<ThemePalette>
     */
    private function loadDirectory(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $palettes = [];
        $entries = scandir($dir);

        if (false === $entries) {
            return [];
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            if (!str_ends_with($entry, '.yaml') && !str_ends_with($entry, '.yml')) {
                continue;
            }

            $file = $dir.'/'.$entry;
            if (!is_file($file)) {
                continue;
            }

            try {
                $palettes[] = $this->loadFile($file);
            } catch (\RuntimeException $e) {
                // Broken theme files are skipped so that a single
                // misconfigured theme does not break the entire TUI.
                // The theme name embedded in the YAML may not match
                // the filename, so surface the path for diagnostics.
                $this->logger->warning('Skipping unparseable theme file', [
                    'file' => $file,
                    'exception' => $e,
                ]);
            }
        }

        return $palettes;
    }
}
