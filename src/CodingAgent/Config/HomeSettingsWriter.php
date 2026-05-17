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
    public function writeDefaultModel(string $filePath, string $model): void
    {
        $this->writeAiKey($filePath, 'default_model', $model);
    }

    public function writeDefaultReasoning(string $filePath, string $reasoning): void
    {
        $this->writeAiKey($filePath, 'default_reasoning', $reasoning);
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
        $pattern = '/^[ \t]*#?[ \t]*'.preg_quote($key, '/').'\s*:.*$/m';

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $line, $content, 1);
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
