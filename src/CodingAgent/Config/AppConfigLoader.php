<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads and merges Hatfield settings from YAML files.
 *
 * Precedence order:
 *   built-in defaults  <  home settings (~/.hatfield/settings.yaml)
 *   <  project settings (<cwd>/.hatfield/settings.yaml)
 *
 * Merging rules:
 *  - Associative arrays merge recursively (project overrides home, home overrides defaults)
 *  - Indexed (list) arrays: project list replaces home list entirely.
 *
 * Path resolution:
 *  - %kernel.project_dir% → app install directory
 *  - ~ → home directory
 *  - Relative paths in defaults resolve against projectDir
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
            $merged = $this->mergeSettings($merged, $homeSettings);
        }

        // Layer 3: Project settings (<cwd>/.hatfield/settings.yaml)
        $projectSettingsPath = rtrim($projectCwd, '/').'/.hatfield/settings.yaml';
        $projectSettings = $this->loadYamlFile($projectSettingsPath);
        if ([] !== $projectSettings) {
            $merged = $this->mergeSettings($merged, $projectSettings);
        }

        // Resolve paths in the merged config
        $merged = $this->resolveConfigPaths($merged, $projectCwd);

        return AppConfig::fromArray($merged);
    }

    /**
     * Recursively merge settings: project overrides home, home overrides defaults.
     *
     * Associative arrays merge recursively.
     * Indexed (list) arrays: project replaces home; home replaces defaults.
     *
     * @param array<string, mixed> $base Lower-priority layer
     * @param array<string, mixed> $over Higher-priority layer
     *
     * @return array<string, mixed>
     */
    public function mergeSettings(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])) {
                // Associative merge for associative arrays; list arrays replace entirely
                if ($this->isAssoc($value) && $this->isAssoc($base[$key])) {
                    $base[$key] = $this->mergeSettings($base[$key], $value);
                } else {
                    $base[$key] = $value;
                }
            } else {
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
