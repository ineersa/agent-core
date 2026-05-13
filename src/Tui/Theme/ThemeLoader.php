<?php

declare(strict_types=1);

namespace Ineersa\Tui\Theme;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads ThemePalette definitions from YAML files.
 *
 * Expected format:
 *
 * ```yaml
 * name: cyberpunk
 * vars:
 *   neon: "#ff00ff"
 *   electric: "#00ffff"
 * colors:
 *   accent: electric
 *   muted: "#718096"
 *   error: "#ff3366"
 *   ...
 * ```
 *
 * Var references are resolved by {@see ThemePalette::fromArray()}.
 */
final class ThemeLoader
{
    /**
     * Load a palette from a YAML file path.
     *
     * @throws \RuntimeException if the file is not readable or parseable
     */
    public function loadFile(string $path): ThemePalette
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
     *
     * @return list<ThemePalette>
     */
    public function loadDirectory(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $palettes = [];
        $files = glob($dir.'/*.{yaml,yml}', \GLOB_BRACE);

        if (false === $files) {
            return [];
        }

        foreach ($files as $file) {
            try {
                $palettes[] = $this->loadFile($file);
            } catch (\RuntimeException $e) {
                // Skip unparseable theme files; the caller may log or ignore
            }
        }

        return $palettes;
    }
}
