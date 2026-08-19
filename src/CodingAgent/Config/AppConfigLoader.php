<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and overlays Hatfield settings layers from YAML files.
 *
 * Precedence order (last wins):
 *   AI catalog (~/.hatfield/ai-catalog.yaml, bootstrapped from config/ai-catalog.yaml)
 *   <  built-in defaults (config/hatfield.defaults.yaml)
 *   <  user settings (~/.hatfield/settings.yaml)
 *   <  project settings (<cwd>/.hatfield/settings.yaml)
 *
 * Catalog providers are complete curated entries; models.dev never gates
 * presence. For known providers, settings stay sparse overlays (scalars win;
 * an explicit `models:` map replaces catalog models wholesale).
 *
 * Each {@see load()} call rereads YAML from disk. Missing user/project files
 * contribute an empty overlay; load never creates ~/.hatfield/settings.yaml.
 * Network I/O never happens here — providers:update writes the user catalog.
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
     * Value semantics:
     *  - 'list': sequential list, each element resolved; non-string entries skipped
     *  - 'strict-list': like 'list', but associative values and non-string or
     *    blank entries are rejected (a dropped path would look configured but
     *    never load)
     *  - 'string': single path resolved as a whole
     */
    private const PATH_CONFIG = [
        '[tui][theme_paths]' => 'list',
        '[sessions][path]' => 'string',
        '[logging][path]' => 'string',
        '[tools][output_cap][path]' => 'string',
        '[tools][background_process][path]' => 'string',
        '[prompts]' => 'strict-list',
        '[agents][paths]' => 'strict-list',
    ];

    public function __construct(
        private readonly SettingsPathResolver $pathResolver,
        private readonly ?AiCatalog $aiCatalog = null,
    ) {
    }

    public function load(string $defaultsPath, string $cwd): SettingsResolutionDTO
    {
        if ('' === $cwd) {
            throw new \InvalidArgumentException(\sprintf('%s::load() requires a non-empty $cwd. Pass %s from the container or an explicit absolute path.', self::class, '%app.cwd%'));
        }

        // Layer 0+1: curated AI catalog (user copy / bundled default) under built-in defaults.
        // Fold catalog into defaultsRaw so SettingsValueResolver provenance still attributes catalog keys
        // to the Defaults layer without expanding SettingsLayerEnum.
        $catalogRaw = $this->aiCatalog?->loadProviders() ?? [];
        $defaultsFileRaw = $this->loadYamlFile($defaultsPath);
        $defaultsRaw = [] !== $catalogRaw ? $this->overlayConfig($catalogRaw, $defaultsFileRaw) : $defaultsFileRaw;

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
                // Provider `models:` maps replace wholesale (pin/trim). Do not deep-merge
                // individual model ids — that would leave unwanted catalog models behind.
                if ('models' === $key && $this->isAssoc($value) && $this->isAssoc($base[$key])) {
                    $base[$key] = $value;
                } elseif ($this->isAssoc($value) && $this->isAssoc($base[$key])) {
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

            if (('list' === $type || 'strict-list' === $type) && \is_array($value)) {
                $strict = 'strict-list' === $type;
                $name = trim(str_replace(['[', ']'], ['.', ''], $path), '.');
                if ($strict && !array_is_list($value)) {
                    throw new \InvalidArgumentException(\sprintf('Invalid value for %s: expected list of strings, got associative array.', $name));
                }
                $resolved = [];
                foreach ($value as $index => $item) {
                    if (!\is_string($item)) {
                        if (!$strict) {
                            continue; // Legacy list keys (e.g. tui.theme_paths) skip non-strings.
                        }
                        throw new \InvalidArgumentException(\sprintf('Invalid value for %s[%d]: expected a non-empty string, got %s.', $name, $index, get_debug_type($item)));
                    }
                    if ($strict && '' === trim($item)) {
                        throw new \InvalidArgumentException(\sprintf('Invalid value for %s[%d]: expected a non-empty string, got blank string.', $name, $index));
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
