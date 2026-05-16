<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Writes targeted scalar values into the home settings YAML file
 * without destroying hand-written comments or unrelated keys.
 *
 * This service uses a line-based text replacement strategy instead
 * of a Yaml::parse() / Yaml::dump() round-trip, which would strip
 * all comments.
 *
 * Limitations:
 *  - Only scalar (string/number/boolean) values at supported key paths.
 *  - Handles both block-style (indented) and inline flow-style YAML.
 *  - When the key does not exist, it inserts after the nearest ancestor.
 *  - When the key is commented out, it uncomments and replaces.
 *  - Deeply nested map/list insertion is not supported; the immediate
 *    ancestor mapping must already exist.
 *
 * Supported key paths (as of AI-03):
 *  - ['ai', 'default_model']
 *  - ['ai', 'default_reasoning']
 *
 * Fallback behaviour:
 *  - If the file does not exist, a {@see \RuntimeException} is thrown
 *    (callers should bootstrap first).
 *  - If insertion would require creating a parent section, the method
 *    falls back to appending the key path to the end of the file.
 */
final class HomeSettingsWriter
{
    /**
     * Write a scalar value at a YAML key path.
     *
     * @param string       $filePath Absolute path to the YAML file
     * @param list<string> $keyPath  Top-down key segments (e.g. ['ai', 'default_model'])
     * @param string       $value    New scalar value (will be YAML-quoted if needed)
     *
     * @throws \RuntimeException if the file does not exist or is not writable
     */
    public function writeScalar(string $filePath, array $keyPath, string $value): void
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Home settings file does not exist: {$filePath}");
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

        $newLines = $this->replaceOrInsert($lines, $keyPath, $value);

        $result = implode($originalEol, $newLines);

        if (false === file_put_contents($filePath, $result)) {
            throw new \RuntimeException("Failed to write home settings file: {$filePath}");
        }
    }

    /**
     * @param list<string> $lines
     * @param list<string> $keyPath
     *
     * @return list<string>
     */
    private function replaceOrInsert(array $lines, array $keyPath, string $value): array
    {
        // Find all top-level key positions (non-commented, zero-indent)
        $sectionPositions = $this->findTopLevelSections($lines);

        $targetSection = $keyPath[0];

        if (!isset($sectionPositions[$targetSection])) {
            // Section does not exist — append at end of file
            return $this->appendNewSection($lines, $keyPath, $value);
        }

        $sectionStart = $sectionPositions[$targetSection];

        // Determine the end of this section (next top-level key, or EOF)
        $sectionEnd = \count($lines);
        foreach ($sectionPositions as $pos) {
            if ($pos > $sectionStart) {
                $sectionEnd = $pos;
                break;
            }
        }

        // Detect indentation level of entries within this section
        $indent = $this->detectSectionIndent($lines, $sectionStart + 1, $sectionEnd);

        // Try to find the target key within the section
        $targetKey = $keyPath[1] ?? '';
        $keyLine = $this->findKeyInSection($lines, $sectionStart + 1, $sectionEnd, $indent, $targetKey);

        if (null !== $keyLine) {
            // Key exists (active or commented) — replace in place
            $lines[$keyLine] = $this->replaceValueOnLine($lines[$keyLine], $targetKey, $indent, $value);
        } else {
            // Key does not exist — insert after the first active entry
            $insertAt = $sectionStart + 1;

            for ($i = $sectionStart + 1; $i < $sectionEnd; ++$i) {
                $trimmed = ltrim($lines[$i]);

                if ('' !== $trimmed && !str_starts_with($trimmed, '#')) {
                    $insertAt = $i;
                    break;
                }
            }

            $newLine = str_repeat(' ', $indent).$targetKey.': '.$this->yamlValue($value);
            array_splice($lines, $insertAt, 0, [$newLine]);
        }

        return $lines;
    }

    /**
     * Find all top-level (unindented) non-comment keys and their line numbers.
     *
     * @param list<string> $lines
     *
     * @return array<string, int> key name => line index
     */
    private function findTopLevelSections(array $lines): array
    {
        $positions = [];

        foreach ($lines as $i => $line) {
            $trimmed = ltrim($line);

            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Top-level key: no leading whitespace before first non-space char
            if (str_starts_with($line, $trimmed)) {
                if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*:/', $trimmed, $m)) {
                    $positions[$m[1]] = $i;
                }
            }
        }

        return $positions;
    }

    /**
     * Detect the indentation level used within a section.
     *
     * @param list<string> $lines
     */
    private function detectSectionIndent(array $lines, int $from, int $to): int
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

        return 4; // sensible default
    }

    /**
     * Find a key within a section, returning its line index or null.
     *
     * Looks for both active and commented-out keys.
     *
     * @param list<string> $lines
     */
    private function findKeyInSection(array $lines, int $from, int $to, int $indent, string $key): ?int
    {
        // Accept indentation within ±2 of the section style
        $minIndent = max(2, $indent - 2);
        $maxIndent = $indent + 2;

        for ($i = $from; $i < $to; ++$i) {
            $trimmed = ltrim($lines[$i]);
            $leading = \strlen($lines[$i]) - \strlen($trimmed);

            if ($leading < $minIndent || $leading > $maxIndent) {
                continue;
            }

            // Match active key: "key: value"
            if (preg_match('/^'.preg_quote($key, '/').'\s*:/', $trimmed)) {
                return $i;
            }

            // Match commented key: "# key: value"
            if (preg_match('/^#\s*'.preg_quote($key, '/').'\s*:/', $trimmed)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Replace the value portion of a line containing a YAML key.
     */
    private function replaceValueOnLine(string $line, string $key, int $indent, string $value): string
    {
        $trimmed = ltrim($line);
        $leading = \strlen($line) - \strlen($trimmed);

        // Was it a commented line? If so, uncomment and fix indentation.
        $wasCommented = str_starts_with($trimmed, '#');

        if ($wasCommented) {
            $trimmed = preg_replace('/^#\s*/', '', $trimmed);
            $leading = $indent;
        }

        $yamlValue = $this->yamlValue($value);

        return str_repeat(' ', $leading).$key.': '.$yamlValue;
    }

    /**
     * Append a new top-level section with the given key and value.
     *
     * @param list<string> $lines
     * @param list<string> $keyPath
     *
     * @return list<string>
     */
    private function appendNewSection(array $lines, array $keyPath, string $value): array
    {
        // Ensure a blank line before the new section
        if (\count($lines) > 0 && '' !== end($lines)) {
            $lines[] = '';
        }

        $section = $keyPath[0];
        $key = $keyPath[1] ?? '';
        $indent = 4;

        $lines[] = $section.':';
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
