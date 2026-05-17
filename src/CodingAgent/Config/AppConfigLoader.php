<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Yaml\Yaml;

final class AppConfigLoader
{
    public function __construct(
        private readonly SettingsPathResolver $pathResolver,
    ) {
    }

    public function load(string $defaultsPath): array
    {
        $projectCwd = getcwd() ?: '/';
        $merged = $this->loadYamlFile($defaultsPath);

        $homeSettingsPath = $this->pathResolver->getHomeDir().'/.hatfield/settings.yaml';
        if (!is_readable($homeSettingsPath) && is_readable($defaultsPath)) {
            $this->bootstrapHomeSettings($defaultsPath, $homeSettingsPath);
        }

        $homeSettings = $this->loadYamlFile($homeSettingsPath);
        if ([] !== $homeSettings) {
            $merged = $this->overlayConfig($merged, $homeSettings);
        }

        $projectSettingsPath = rtrim($projectCwd, '/').'/.hatfield/settings.yaml';
        $projectSettings = $this->loadYamlFile($projectSettingsPath);
        if ([] !== $projectSettings) {
            $merged = $this->overlayConfig($merged, $projectSettings);
        }

        return $this->resolveConfigPaths($merged);
    }

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

    private function resolveConfigPaths(array $data): array
    {
        $projectCwd = getcwd() ?: '/';
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
            $data['sessions']['path'] = $this->pathResolver->resolve($data['sessions']['path'], $projectCwd);
        }

        return $data;
    }

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

    private function isAssoc(array $arr): bool
    {
        if ([] === $arr) {
            return false;
        }

        return array_keys($arr) !== range(0, \count($arr) - 1);
    }

    private function bootstrapHomeSettings(string $defaultsPath, string $homeSettingsPath): void
    {
        $dir = \dirname($homeSettingsPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        copy($defaultsPath, $homeSettingsPath);
    }
}
