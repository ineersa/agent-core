<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Persists home AI defaults (currently default_model and default_reasoning)
 * into ~/.hatfield/settings.yaml while preserving comments and unrelated keys.
 *
 * Uses line-based text replacement so hand-written YAML comments survive.
 * A Yaml::parse() / Yaml::dump() round-trip would strip all comments.
 *
 * For now, exactly two keys are supported:
 *  - ai.default_model
 *  - ai.default_reasoning
 *
 * If the ai: section is absent, it is appended at the end of the file.
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
     * @throws \RuntimeException if the file does not exist, is not readable, or is not writable
     */
    private function writeAiKey(string $filePath, string $key, string $value): void
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Home settings file does not exist: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new \RuntimeException("Home settings file is not readable: {$filePath}");
        }

        if (!is_writable($filePath)) {
            throw new \RuntimeException("Home settings file is not writable: {$filePath}");
        }

        $content = file_get_contents($filePath);

        if (false === $content) {
            throw new \RuntimeException("Failed to read home settings file: {$filePath}");
        }

        $originalEol = $this->detectEol($content);

        // Normalize line endings for consistent line-splitting
        if ("\r\n" === $originalEol) {
            $content = str_replace("\r\n", "\n", $content);
        }

        $lines = explode("\n", $content);

        $newLines = $this->replaceOrInsertAiKey($lines, $key, $value);

        $result = implode($originalEol, $newLines);

        if (false === file_put_contents($filePath, $result)) {
            throw new \RuntimeException("Failed to write home settings file: {$filePath}");
        }
    }

    /**
     * @param list<string> $lines (normalized \n line endings)
     * @param string       $key   Key name under ai: (e.g. "default_model")
     * @param string       $value New scalar YAML value
     *
     * @return list<string>
     */
    private function replaceOrInsertAiKey(array $lines, string $key, string $value): array
    {
        // Locate the top-level ai: section
        $aiLine = $this->findTopLevelKey($lines, 'ai');

        if (null === $aiLine) {
            return $this->appendAiSection($lines, $key, $value);
        }

        // Find the end of the ai: section (next top-level key, or EOF)
        $sectionEnd = $this->findSectionEnd($lines, $aiLine);

        // Detect indentation of entries within the ai: section
        $indent = $this->detectIndent($lines, $aiLine + 1, $sectionEnd);

        // Try to find the key within the ai: section (active or commented)
        $keyLine = $this->findKeyInSection($lines, $aiLine + 1, $sectionEnd, $indent, $key);

        if (null !== $keyLine) {
            // Key exists — replace in place (uncommenting if needed)
            $lines[$keyLine] = $this->replaceKeyLine($lines[$keyLine], $key, $indent, $value);
        } else {
            // Key not present — insert after the first active entry in the section
            $insertAt = $aiLine + 1;

            for ($i = $aiLine + 1; $i < $sectionEnd; ++$i) {
                $trimmed = ltrim($lines[$i]);

                if ('' !== $trimmed && !str_starts_with($trimmed, '#')) {
                    $insertAt = $i;
                    break;
                }
            }

            $newLine = str_repeat(' ', $indent).$key.': '.$this->yamlValue($value);
            array_splice($lines, $insertAt, 0, [$newLine]);
        }

        return $lines;
    }

    /**
     * Find a top-level (zero-indent) non-commented YAML key.
     *
     * @param list<string> $lines
     */
    private function findTopLevelKey(array $lines, string $keyName): ?int
    {
        foreach ($lines as $i => $line) {
            $trimmed = ltrim($line);

            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Top-level: no leading whitespace
            if (!str_starts_with($line, $trimmed)) {
                continue;
            }

            if (preg_match('/^'.preg_quote($keyName, '/').'\s*:/', $trimmed)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Find the end of a section starting at the given line.
     * Returns the line index of the next top-level key, or count($lines).
     *
     * @param list<string> $lines
     */
    private function findSectionEnd(array $lines, int $sectionLine): int
    {
        for ($i = $sectionLine + 1, $max = \count($lines); $i < $max; ++$i) {
            $trimmed = ltrim($lines[$i]);

            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Top-level key — new section starts
            if (str_starts_with($lines[$i], $trimmed)) {
                return $i;
            }
        }

        return \count($lines);
    }

    /**
     * Detect indentation level of entries within a section.
     *
     * @param list<string> $lines
     */
    private function detectIndent(array $lines, int $from, int $to): int
    {
        for ($i = $from; $i < $to; ++$i) {
            $trimmed = ltrim($lines[$i]);

            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }

            $leading = \strlen($lines[$i]) - \strlen($trimmed);

            if ($leading > 0) {
                return $leading;
            }
        }

        return 4;
    }

    /**
     * Find a key within a section (active or commented-out).
     *
     * @param list<string> $lines
     */
    private function findKeyInSection(array $lines, int $from, int $to, int $indent, string $key): ?int
    {
        $minIndent = max(2, $indent - 2);
        $maxIndent = $indent + 2;

        for ($i = $from; $i < $to; ++$i) {
            $trimmed = ltrim($lines[$i]);
            $leading = \strlen($lines[$i]) - \strlen($trimmed);

            if ($leading < $minIndent || $leading > $maxIndent) {
                continue;
            }

            // Active key:  "key: value"
            if (preg_match('/^'.preg_quote($key, '/').'\s*:/', $trimmed)) {
                return $i;
            }

            // Commented key: "# key: value"
            if (preg_match('/^#\s*'.preg_quote($key, '/').'\s*:/', $trimmed)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Replace the value on a key line, uncommenting if it was commented out.
     */
    private function replaceKeyLine(string $line, string $key, int $indent, string $value): string
    {
        $trimmed = ltrim($line);
        $wasCommented = str_starts_with($trimmed, '#');

        if ($wasCommented) {
            $trimmed = preg_replace('/^#\s*/', '', $trimmed);
        }

        return str_repeat(' ', $indent).$key.': '.$this->yamlValue($value);
    }

    /**
     * Append a new ai: section with the given key and value.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function appendAiSection(array $lines, string $key, string $value): array
    {
        // Ensure a blank line before the new section
        if (\count($lines) > 0 && '' !== end($lines)) {
            $lines[] = '';
        }

        $indent = 4;

        $lines[] = 'ai:';
        $lines[] = str_repeat(' ', $indent).$key.': '.$this->yamlValue($value);

        return $lines;
    }

    /**
     * Convert a PHP value to a YAML-safe scalar representation.
     *
     * Numbers, booleans/null, and plain-safe strings are left bare.
     * Only strings with YAML-significant characters are single-quoted.
     */
    private function yamlValue(string $value): string
    {
        if ('' === $value) {
            return "''";
        }

        // YAML booleans / null — pass through
        if (preg_match('/^(true|false|yes|no|on|off|null|~)$/i', $value)) {
            return $value;
        }

        // Numbers — pass through
        if (preg_match('/^-?\d+(\.\d+)?(?:[eE][+-]?\d+)?$/', $value)) {
            return $value;
        }

        // Characters that force quoting in YAML plain scalars:
        //   : # { } [ ] , & * ! | > ' " @ % `
        // Also quote leading "- " (list syntax) and trailing ":".
        if (preg_match('/[:#{}[\]\,&*!|>\'"@%`]/', $value)
            || str_starts_with($value, '- ')
            || str_ends_with($value, ':')
        ) {
            $escaped = str_replace("'", "''", $value);

            return "'".$escaped."'";
        }

        return $value;
    }

    private function detectEol(string $content): string
    {
        if (str_contains($content, "\r\n")) {
            return "\r\n";
        }

        return "\n";
    }
}
