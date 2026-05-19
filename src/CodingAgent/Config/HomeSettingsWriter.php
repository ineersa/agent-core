<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Persists ai.default_model and ai.default_reasoning into the home
 * settings YAML file without destroying hand-written comments.
 *
 * Uses regex line replacement so comments survive — a Yaml::parse/dump
 * round-trip would strip them. Only the two known keys are supported.
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
     * Persist the full favorite models list to home settings.
     *
     * @param list<string> $models List of "provider/modelname" strings
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
     * Write a list value under the ai section, preserving comments.
     *
     * Only replaces an *active* (non-commented) key.  Commented-out
     * lines with the same key are left untouched — if the user
     * commented out a key to disable it, the writer inserts a fresh
     * active key instead of silently uncommenting the old one.
     *
     * @param list<string> $values List of strings
     */
    private function writeAiListKey(string $filePath, string $key, array $values): void
    {
        $content = @file_get_contents($filePath);

        if (false === $content) {
            throw new \RuntimeException(\sprintf('Cannot read home settings file: %s', $filePath));
        }

        // Format as YAML flow sequence: [a, b, c]
        if ([] === $values) {
            $line = \sprintf('    %s: []', $key);
        } else {
            $quoted = array_map(fn (string $v): string => $this->yamlScalar($v), $values);
            $line = \sprintf('    %s: [%s]', $key, implode(', ', $quoted));
        }

        $activePattern = '/^    '.preg_quote($key, '/').'\s*:.*$/m';

        if (preg_match($activePattern, $content)) {
            // Replace the active key (only uncommented, with 4-space indent)
            $content = preg_replace($activePattern, $line, $content, 1);
        } elseif (preg_match('/^ai:\s*$/m', $content)) {
            // ai section exists — insert new active key below it
            $content = preg_replace('/^ai:\s*$/m', "ai:\n".$line, $content, 1);
        } else {
            // No ai section — append
            $content = rtrim($content)."\n\nai:\n".$line."\n";
        }

        if (false === @file_put_contents($filePath, $content)) {
            throw new \RuntimeException(\sprintf('Cannot write home settings file: %s', $filePath));
        }
    }

    /**
     * @throws \RuntimeException when the file cannot be read or written
     */
    private function writeAiKey(string $filePath, string $key, string $value): void
    {
        $content = @file_get_contents($filePath);

        if (false === $content) {
            throw new \RuntimeException(\sprintf('Cannot read home settings file: %s', $filePath));
        }

        $line = \sprintf('    %s: %s', $key, $this->yamlScalar($value));

        // Only match active (non-commented) keys with 4-space indent.
        // This prevents accidentally uncommenting a key the user
        // intentionally disabled.
        $activePattern = '/^    '.preg_quote($key, '/').'\s*:.*$/m';

        if (preg_match($activePattern, $content)) {
            $content = preg_replace($activePattern, $line, $content, 1);
        } elseif (preg_match('/^ai:\s*$/m', $content)) {
            $content = preg_replace('/^ai:\s*$/m', "ai:\n".$line, $content, 1);
        } else {
            $content = rtrim($content)."\n\nai:\n".$line."\n";
        }

        if (false === @file_put_contents($filePath, $content)) {
            throw new \RuntimeException(\sprintf('Cannot write home settings file: %s', $filePath));
        }
    }

    /**
     * Quote strings that contain YAML-significant characters.
     * Plain-safe values (e.g. "zai/glm-5.1", "high", "off") stay unquoted.
     */
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
