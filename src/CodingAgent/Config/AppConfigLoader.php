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
 *  - %%kernel.project_dir%% → app install directory (via SettingsPathResolver::$appRoot)
 *  - ~ → home directory
 *  - Relative paths resolve against the canonical runtime cwd passed to
 *    {@see load()}, which comes from the %%app.cwd%% container parameter
 *    (resolved from HATFIELD_CWD or kernel.project_dir).
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
     * @param string $cwd          Canonical runtime working directory
     *                             (from %%app.cwd%% / HATFIELD_CWD)
     *
     * @return array<string, mixed>
     */
    public function load(string $defaultsPath, string $cwd): array
    {
        // Layer 1: Built-in defaults (shipped with the app)
        $merged = $this->loadYamlFile($defaultsPath);

        // Layer 2: Home settings (~/.hatfield/settings.yaml)
        $homeSettingsPath = $this->pathResolver->getHomeDir().'/.hatfield/settings.yaml';

        // Bootstrap the home settings file by copying the built-in defaults
        // if it does not exist yet (first-launch behaviour). Do not overwrite
        // an existing home file.
        if (!is_readable($homeSettingsPath) && is_readable($defaultsPath)) {
            $this->bootstrapHomeSettings($defaultsPath, $homeSettingsPath);
        }

        $homeSettings = $this->loadYamlFile($homeSettingsPath);
        if ([] !== $homeSettings) {
            $merged = $this->overlayConfig($merged, $homeSettings);
        }

        // Layer 3: Project settings (<cwd>/.hatfield/settings.yaml)
        $projectSettingsPath = rtrim($cwd, '/').'/.hatfield/settings.yaml';
        $projectSettings = $this->loadYamlFile($projectSettingsPath);
        if ([] !== $projectSettings) {
            $merged = $this->overlayConfig($merged, $projectSettings);
        }

        return $this->resolveConfigPaths($merged, $cwd);
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
     * Currently resolves {@see tui::$themePaths}, {@see sessions.path},
     * and {@see logging.path}. Extend as more path-containing config keys
     * are added.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function resolveConfigPaths(array $data, string $cwd): array
    {
        if (isset($data['tui']['theme_paths']) && \is_array($data['tui']['theme_paths'])) {
            $resolved = [];
            foreach ($data['tui']['theme_paths'] as $path) {
                if (!\is_string($path)) {
                    continue;
                }
                $resolved[] = $this->pathResolver->resolve($path, $cwd);
            }
            $data['tui']['theme_paths'] = $resolved;
        }

        if (isset($data['sessions']['path']) && \is_string($data['sessions']['path'])) {
            $data['sessions']['path'] = $this->pathResolver->resolve(
                $data['sessions']['path'],
                $cwd,
            );
        }

        if (isset($data['logging']['path']) && \is_string($data['logging']['path'])) {
            $data['logging']['path'] = $this->pathResolver->resolve(
                $data['logging']['path'],
                $cwd,
            );
        }

        if (isset($data['tools']['output_cap']['path']) && \is_string($data['tools']['output_cap']['path'])) {
            $data['tools']['output_cap']['path'] = $this->pathResolver->resolve(
                $data['tools']['output_cap']['path'],
                $cwd,
            );
        }

        if (isset($data['tools']['background_process']['path']) && \is_string($data['tools']['background_process']['path'])) {
            $data['tools']['background_process']['path'] = $this->pathResolver->resolve(
                $data['tools']['background_process']['path'],
                $cwd,
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

    /**
     * Create the home settings file by copying the built-in defaults
     * on first launch.
     *
     * Users edit the copied file to set personal API keys, default model,
     * reasoning level, and other overrides. The file is never auto-overwritten.
     *
     * The {@see config/hatfield.defaults.yaml} file is designed with comments
     * that double as documentation, so the copied home file is self-documenting.
     */
    private function bootstrapHomeSettings(string $defaultsPath, string $homeSettingsPath): void
    {
        $dir = \dirname($homeSettingsPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        copy($defaultsPath, $homeSettingsPath);
    }
}
