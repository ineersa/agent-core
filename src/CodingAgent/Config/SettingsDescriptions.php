<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

final class SettingsDescriptions
{
    /**
     * Docs from raw defaults lines only; Symfony YAML remains the parser.
     * Paths are constrained to defaultsRaw so commented-out examples never match.
     * Only immediately adjacent comment blocks describe a key; blank lines reset pending prose.
     *
     * @param array<string, mixed> $defaultsRaw
     *
     * @return array<string, string>
     */
    public function extract(string $defaultsPath, array $defaultsRaw): array
    {
        $raw = @file_get_contents($defaultsPath);
        if (false === $raw) {
            throw new \RuntimeException(\sprintf('Unable to read defaults resource: %s', $defaultsPath));
        }

        $known = $this->knownPaths($defaultsRaw);
        $descriptions = [];
        $stack = [];
        $pending = [];
        $lines = preg_split("/\R/", $raw);
        foreach (false === $lines ? [] : $lines as $line) {
            if (preg_match('/^\s*#/', $line)) {
                $content = ltrim(substr(ltrim($line), 1));
                // Controlled defaults keys are lowercase/snake/kebab; keep capitalized prose (e.g. Precedence: ...).
                if (str_contains($content, '---') || str_starts_with($content, '===')
                    || preg_match('/^[a-z_][\w-]*\s*:/', $content) || preg_match('/^-\s+\S/', $content)) {
                    $pending = [];
                    continue;
                }
                if ('' !== $content) {
                    $pending[] = $content;
                }
                continue;
            }
            if ('' === trim($line) || !preg_match('/^( *)([A-Za-z_][\w-]*)\s*:/', $line, $match)) {
                $pending = [];
                continue;
            }

            $indent = \strlen($match[1]);
            while ([] !== $stack && $stack[array_key_last($stack)]['indent'] >= $indent) {
                array_pop($stack);
            }
            $stack[] = ['indent' => $indent, 'key' => $match[2]];
            $path = implode('.', array_column($stack, 'key'));
            if (isset($known[$path]) && [] !== $pending) {
                $descriptions[$path] = implode(' ', $pending);
            }
            $pending = [];
        }

        return $descriptions;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, true>
     */
    private function knownPaths(array $data, string $prefix = ''): array
    {
        $paths = [];
        foreach ($data as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            $paths[$path] = true;
            if (\is_array($value) && [] !== $value && !array_is_list($value)) {
                $paths += $this->knownPaths($value, $path);
            }
        }

        return $paths;
    }
}
