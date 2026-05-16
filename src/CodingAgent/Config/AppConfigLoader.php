<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads and overlays Hatfield settings layers from YAML files.
 *
 * Precedence order (last wins):
 *   built-in defaults  <  home settings (~/.hatfield/settings.yaml)
 *   <  project settings (<cwd>/.hatfield/settings.yaml)
 *
 * Overlay semantics (implemented in {@see overlayConfig}):
 *  - Associative arrays: recursive deep overlay — keys present in the higher-
 *    priority layer override matching keys in the lower layer; keys only in the
 *    lower layer survive untouched.
 *  - Indexed/sequential list arrays: higher-priority entire list replaces the
 *    lower-priority list. Lists never append or index-merge.
 *  - Scalar values (string, int, float, bool): higher-priority value wins;
 *    lower-priority value is discarded.
 *  - null values in a higher layer: replace whatever was below, same as any
 *    other scalar.
 *
 * Why not array_merge_recursive()?
 *  - array_merge_recursive() turns conflicting scalar values into arrays:
 *    array_merge_recursive(['theme' => 'cyberpunk'], ['theme' => 'nord'])
 *    produces ['theme' => ['cyberpunk', 'nord']] — two values where a single
 *    winning value is expected. Config override semantics require the
 *    higher-priority scalar to win cleanly, not to create an array.
 *  - array_replace_recursive() handles scalars correctly but uses array key
 *    identity for list elements, which can cause partial replacement when a
 *    whole-list replace is intended.
 *
 * Path resolution:
 *  - %kernel.project_dir% → app install directory (via SettingsPathResolver::$appRoot)
 *  - ~ → home directory
 *  - Relative paths in defaults resolve against projectCwd
 *  - Relative paths in home settings resolve against homeDir
 *  - Relative paths in project settings resolve against projectCwd
 */
final class AppConfigLoader
{
    public function __construct(
        private readonly SettingsPathResolver $pathResolver,
    ) {
    }

    /**
     * Load and merge all settings layers into a single resolved config.
     *
     * @param string $defaultsPath Path to built-in defaults YAML
     * @param string $projectCwd   Active project working directory
     */
    public function load(string $defaultsPath, string $projectCwd): AppConfig
    {
        // Layer 1: Built-in defaults (shipped with the app)
        $merged = $this->loadYamlFile($defaultsPath);

        // Layer 2: Home settings (~/.hatfield/settings.yaml)
        $homeSettingsPath = $this->pathResolver->getHomeDir().'/.hatfield/settings.yaml';
        $homeSettings = $this->loadYamlFile($homeSettingsPath);
        if ([] !== $homeSettings) {
            $merged = $this->overlayConfig($merged, $homeSettings);
        }

        // Layer 3: Project settings (<cwd>/.hatfield/settings.yaml)
        $projectSettingsPath = rtrim($projectCwd, '/').'/.hatfield/settings.yaml';
        $projectSettings = $this->loadYamlFile($projectSettingsPath);
        if ([] !== $projectSettings) {
            $merged = $this->overlayConfig($merged, $projectSettings);
        }

        // Resolve paths in the merged config
        $merged = $this->resolveConfigPaths($merged, $projectCwd);

        return AppConfig::fromArray($merged);
    }

    /**
     * Recursively overlay config layers so the higher-priority layer wins.
     *
     * Rules (per key):
     *  1. Both sides are associative arrays → recurse (deep overlay).
     *  2. Either side is a list (sequential array) → higher-priority list
     *     replaces the lower-priority list entirely. Lists never append.
     *  3. One or both sides are scalar/null → higher-priority value wins.
     *
     * These rules produce the natural read of a YAML config override:
     * writing a key at any level in a higher-priority file replaces
     * whatever was there; writing a partial associative subtree overlays.
     *
     * @param array<string, mixed> $base Lower-priority layer (defaults)
     * @param array<string, mixed> $over Higher-priority layer (home or project)
     *
     * @return array<string, mixed>
     */
    public function overlayConfig(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])) {
                // Both sides are arrays: deep overlay for associative maps;
                // whole-list replacement for sequential lists.
                if ($this->isAssoc($value) && $this->isAssoc($base[$key])) {
                    $base[$key] = $this->overlayConfig($base[$key], $value);
                } else {
                    $base[$key] = $value;
                }
            } else {
                // Scalar, null, or type mismatch → higher-priority wins.
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Resolve path placeholders in the merged config.
     *
     * Only resolves the known tui.theme_paths key for now.
     * Extend as more path-containing config keys are added.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function resolveConfigPaths(array $data, string $projectCwd): array
    {
        if (isset($data['tui']['theme_paths']) && \is_array($data['tui']['theme_paths'])) {
            $resolved = [];
            foreach ($data['tui']['theme_paths'] as $path) {
                if (!\is_string($path)) {
                    continue;
                }
                $resolved[] = $this->pathResolver->resolve($path, $projectCwd);
            }
            $data['tui']['theme_paths'] = $resolved;
        }

        if (isset($data['sessions']['path']) && \is_string($data['sessions']['path'])) {
            $data['sessions']['path'] = $this->pathResolver->resolve(
                $data['sessions']['path'],
                $projectCwd,
            );
        }

        return $data;
    }

    /**
     * Load a YAML file, returning an associative array or [] on failure.
     *
     * @return array<string, mixed>
     */
    private function loadYamlFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if (false === $content) {
            return [];
        }

        $data = Yaml::parse($content);

        return \is_array($data) ? $data : [];
    }

    /**
     * Check whether an array is associative (has non-sequential string keys).
     *
     * @param array<mixed> $arr
     */
    private function isAssoc(array $arr): bool
    {
        if ([] === $arr) {
            return false;
        }

        return array_keys($arr) !== range(0, \count($arr) - 1);
    }
}
