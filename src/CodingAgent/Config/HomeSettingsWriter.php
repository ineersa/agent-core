<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Persists ai.* keys into the home settings YAML file as sparse overrides.
 *
 * Uses Symfony YAML parse → mutate array → dump. Comment and formatting
 * preservation is intentionally not attempted; defaults already document
 * every setting. The home file is created on first mutation as a minimal
 * sparse document (no full defaults snapshot).
 */
final class HomeSettingsWriter
{
    public function __construct(
        private readonly SettingsPathResolver $pathResolver,
    ) {
    }

    public function writeDefaultModel(string $model): void
    {
        $this->writeAiValue('default_model', $model);
    }

    public function writeDefaultReasoning(string $reasoning): void
    {
        $this->writeAiValue('default_reasoning', $reasoning);
    }

    /**
     * Persist the full favorite models list to home settings.
     *
     * @param list<string> $models List of "provider/modelname" strings
     */
    public function writeFavoriteModels(array $models): void
    {
        $this->writeAiValue('favorite_models', $models);
    }

    /**
     * @throws \RuntimeException when the file cannot be read/written or structure is malformed
     */
    private function writeAiValue(string $key, mixed $value): void
    {
        $filePath = $this->homeSettingsPath();
        $settings = $this->readHomeSettings($filePath);

        if (!\array_key_exists('ai', $settings)) {
            $settings['ai'] = [];
        } elseif (!$this->isMapping($settings['ai'])) {
            throw new \RuntimeException(\sprintf('Home settings key "ai" must be a mapping in %s; got %s', $filePath, get_debug_type($settings['ai'])));
        }

        $settings['ai'][$key] = $value;
        $this->writeHomeSettings($filePath, $settings);
    }

    private function homeSettingsPath(): string
    {
        return $this->pathResolver->getHomeDir().'/.hatfield/settings.yaml';
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the file exists but cannot be read, parsed, or is not a mapping
     */
    private function readHomeSettings(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        if (!is_readable($filePath)) {
            throw new \RuntimeException(\sprintf('Cannot read home settings file: %s', $filePath));
        }

        $content = file_get_contents($filePath);
        if (false === $content) {
            throw new \RuntimeException(\sprintf('Cannot read home settings file: %s', $filePath));
        }

        if ('' === trim($content)) {
            return [];
        }

        try {
            $parsed = Yaml::parse($content);
        } catch (ParseException $e) {
            throw new \RuntimeException(\sprintf('Cannot parse home settings file: %s (%s)', $filePath, $e->getMessage()), 0, $e);
        }

        if (null === $parsed) {
            return [];
        }

        if (!$this->isMapping($parsed)) {
            throw new \RuntimeException(\sprintf('Home settings root document must be a mapping in %s; got %s', $filePath, get_debug_type($parsed)));
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @throws \RuntimeException when the file cannot be written
     */
    private function writeHomeSettings(string $filePath, array $settings): void
    {
        $dir = \dirname($filePath);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create home settings directory: %s', $dir));
        }

        $yaml = Yaml::dump($settings, 4, 4);
        if (false === @file_put_contents($filePath, $yaml)) {
            throw new \RuntimeException(\sprintf('Cannot write home settings file: %s', $filePath));
        }
    }

    /**
     * Empty [] is treated as an acceptable empty mapping (YAML map/list ambiguity).
     * Non-empty sequential lists are rejected so we never mutate list roots into mixed maps.
     */
    private function isMapping(mixed $value): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        return [] === $value || !array_is_list($value);
    }
}
