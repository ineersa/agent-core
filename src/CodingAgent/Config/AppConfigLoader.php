<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and overlays Hatfield settings layers from YAML files.
 *
 * Precedence order (last wins):
 *   built-in defaults  <  user settings (~/.hatfield/settings.yaml)
 *   <  project settings (<cwd>/.hatfield/settings.yaml)
 *
 * Each {@see load()} call rereads YAML from disk. Missing user/project files
 * contribute an empty overlay; load never creates ~/.hatfield/settings.yaml.
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
 *  - Relative paths resolve against the canonical runtime cwd passed to
 *    {@see load()}, which comes from the %app.cwd% container parameter
 *    (resolved from HATFIELD_CWD or kernel.project_dir).
 *  - Raw layer snapshots in {@see SettingsResolutionDTO} keep unresolved path
 *    strings; only {@see SettingsResolutionDTO::$effective} receives resolved paths.
 */
final class AppConfigLoader
{
    /**
     * Path-bearing config keys resolved at load time.
     *
     * Register new path-bearing settings here instead of adding one-off
     * conditionals in {@see resolveConfigPaths()}.
     *
     * Keys use Symfony PropertyAccess bracket notation for array access.
     * The value indicates whether the resolved value is a list (each element
     * resolved individually) or a string (resolved as a single path).
     */
    private const PATH_CONFIG = [
        '[tui][theme_paths]' => 'list',
        '[sessions][path]' => 'string',
        '[logging][path]' => 'string',
        '[tools][output_cap][path]' => 'string',
        '[tools][background_process][path]' => 'string',
        '[prompts]' => 'list',
        '[agents][paths]' => 'list',
    ];

    public function __construct(
        private readonly SettingsPathResolver $pathResolver,
    ) {
    }

    public function load(string $defaultsPath, string $cwd): SettingsResolutionDTO
    {
        if ('' === $cwd) {
            throw new \InvalidArgumentException(\sprintf('%s::load() requires a non-empty $cwd. Pass %s from the container or an explicit absolute path.', self::class, '%app.cwd%'));
        }

        // Layer 1: Built-in defaults (shipped with the app)
        $defaultsRaw = $this->loadYamlFile($defaultsPath);

        // Layer 2: User settings (~/.hatfield/settings.yaml), sparse overrides only
        $userSettingsPath = $this->pathResolver->getHomeDir().'/.hatfield/settings.yaml';
        $userRaw = $this->loadYamlFile($userSettingsPath);

        // Layer 3: Project settings (<cwd>/.hatfield/settings.yaml)
        $projectSettingsPath = rtrim($cwd, '/').'/.hatfield/settings.yaml';
        $projectRaw = $this->loadYamlFile($projectSettingsPath);

        $merged = $defaultsRaw;
        if ([] !== $userRaw) {
            $merged = $this->overlayConfig($merged, $userRaw);
        }
        if ([] !== $projectRaw) {
            $merged = $this->overlayConfig($merged, $projectRaw);
        }

        $effective = $this->resolveConfigPaths($merged, $cwd);

        return new SettingsResolutionDTO(
            defaultsRaw: $defaultsRaw,
            userRaw: $userRaw,
            projectRaw: $projectRaw,
            effective: $effective,
        );
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
     * @param array<string, mixed> $base Lower-priority layer (defaults)
     * @param array<string, mixed> $over Higher-priority layer (user or project)
     *
     * @return array<string, mixed>
     */
    public function overlayConfig(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])) {
                if ($this->isAssoc($value) && $this->isAssoc($base[$key])) {
                    $base[$key] = $this->overlayConfig($base[$key], $value);
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
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function resolveConfigPaths(array $data, string $cwd): array
    {
        $accessor = PropertyAccess::createPropertyAccessor();

        foreach (self::PATH_CONFIG as $path => $type) {
            try {
                $value = $accessor->getValue($data, $path);
            } catch (\Exception) {
                // PropertyAccessor throws for keys absent from this config — skip.
                continue;
            }

            if ('list' === $type && \is_array($value)) {
                $resolved = [];
                foreach ($value as $item) {
                    if (!\is_string($item)) {
                        continue;
                    }
                    $resolved[] = $this->pathResolver->resolve($item, $cwd);
                }
                $accessor->setValue($data, $path, $resolved);
            } elseif ('string' === $type && \is_string($value)) {
                $accessor->setValue($data, $path, $this->pathResolver->resolve($value, $cwd));
            }
        }

        return $data;
    }

    /**
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
