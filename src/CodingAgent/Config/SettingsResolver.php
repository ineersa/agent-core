<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Yaml\Yaml;

/**
 * Stateless resolver for Hatfield settings layers (defaults < home < project).
 *
 * Each {@see resolve()} call rereads YAML from disk. User and project files are
 * sparse overrides; missing files contribute an empty overlay.
 */
final class SettingsResolver
{
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

    public function resolve(string $defaultsPath, string $cwd): SettingsResolutionDTO
    {
        if ('' === $cwd) {
            throw new \InvalidArgumentException(\sprintf('%s::resolve() requires a non-empty $cwd. Pass %s from the container or an explicit absolute path.', self::class, '%app.cwd%'));
        }

        $defaultsRaw = $this->loadYamlFile($defaultsPath);

        $homeSettingsPath = $this->pathResolver->getHomeDir().'/.hatfield/settings.yaml';
        $homeRaw = $this->loadYamlFile($homeSettingsPath);

        $projectSettingsPath = rtrim($cwd, '/').'/.hatfield/settings.yaml';
        $projectRaw = $this->loadYamlFile($projectSettingsPath);

        $merged = $defaultsRaw;
        if ([] !== $homeRaw) {
            $merged = $this->overlayConfig($merged, $homeRaw);
        }
        if ([] !== $projectRaw) {
            $merged = $this->overlayConfig($merged, $projectRaw);
        }

        $effective = $this->resolveConfigPaths($merged, $cwd);

        return new SettingsResolutionDTO(
            defaultsRaw: $defaultsRaw,
            homeRaw: $homeRaw,
            projectRaw: $projectRaw,
            effective: $effective,
        );
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
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
    public function loadYamlFile(string $path): array
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
