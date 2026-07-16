<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Persists ai.* keys into the home settings YAML file without destroying
 * hand-written comments on existing files.
 *
 * The home file is created on first mutation as a minimal sparse YAML document.
 */
final class HomeSettingsWriter
{
    public function __construct(
        private readonly SettingsPathResolver $pathResolver,
    ) {
    }

    public function writeDefaultModel(string $model): void
    {
        $this->writeAiKey($this->homeSettingsPath(), 'default_model', $model);
    }

    public function writeDefaultReasoning(string $reasoning): void
    {
        $this->writeAiKey($this->homeSettingsPath(), 'default_reasoning', $reasoning);
    }

    /**
     * @param list<string> $models
     */
    public function writeFavoriteModels(array $models): void
    {
        $this->writeAiListKey($this->homeSettingsPath(), 'favorite_models', $models);
    }

    private function homeSettingsPath(): string
    {
        return $this->pathResolver->getHomeDir().'/.hatfield/settings.yaml';
    }

    /**
     * @param list<string> $values
     */
    private function writeAiListKey(string $filePath, string $key, array $values): void
    {
        $content = $this->readOrCreateHomeSettings($filePath);

        if ([] === $values) {
            $line = \sprintf('    %s: []', $key);
        } else {
            $quoted = array_map(fn (string $v): string => $this->yamlScalar($v), $values);
            $line = \sprintf('    %s: [%s]', $key, implode(', ', $quoted));
        }

        $activePattern = '/^    '.preg_quote($key, '/').'\s*:.*$/m';

        if (preg_match($activePattern, $content)) {
            $content = preg_replace($activePattern, $line, $content, 1);
        } elseif (preg_match('/^ai:\s*$/m', $content)) {
            $content = preg_replace('/^ai:\s*$/m', "ai:\n".$line, $content, 1);
        } else {
            $content = rtrim($content)."\n\nai:\n".$line."\n";
        }

        $this->writeHomeSettings($filePath, $content);
    }

    private function writeAiKey(string $filePath, string $key, string $value): void
    {
        $content = $this->readOrCreateHomeSettings($filePath);

        $line = \sprintf('    %s: %s', $key, $this->yamlScalar($value));

        $activePattern = '/^    '.preg_quote($key, '/').'\s*:.*$/m';

        if (preg_match($activePattern, $content)) {
            $content = preg_replace($activePattern, $line, $content, 1);
        } elseif (preg_match('/^ai:\s*$/m', $content)) {
            $content = preg_replace('/^ai:\s*$/m', "ai:\n".$line, $content, 1);
        } else {
            $content = rtrim($content)."\n\nai:\n".$line."\n";
        }

        $this->writeHomeSettings($filePath, $content);
    }

    private function readOrCreateHomeSettings(string $filePath): string
    {
        if (!file_exists($filePath)) {
            $dir = \dirname($filePath);
            if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
                throw new \RuntimeException(\sprintf('Cannot create home settings directory: %s', $dir));
            }

            return '';
        }

        if (!is_readable($filePath)) {
            throw new \RuntimeException(\sprintf('Cannot read home settings file: %s', $filePath));
        }

        $content = file_get_contents($filePath);
        if (false === $content) {
            throw new \RuntimeException(\sprintf('Cannot read home settings file: %s', $filePath));
        }

        return $content;
    }

    private function writeHomeSettings(string $filePath, string $content): void
    {
        if (false === @file_put_contents($filePath, $content)) {
            throw new \RuntimeException(\sprintf('Cannot write home settings file: %s', $filePath));
        }
    }

    private function yamlScalar(string $value): string
    {
        if ('' === $value) {
            return "''";
        }

        if (preg_match('/[:#{}[\]\,&*!|>\'"@%`]/', $value)
            || str_starts_with($value, '- ')
            || str_ends_with($value, ':')
        ) {
            return "'".str_replace("'", "''", $value)."'";
        }

        return $value;
    }
}
