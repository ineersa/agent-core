<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Yaml\Yaml;

/**
 * Theme-backed key/value coloring for tool argument YAML lines (display only).
 *
 * Keys use {@see TuiTheme::muted()} for subtle labels; values use {@see TuiTheme::text()}
 * so argument bodies match default transcript foreground instead of bright accents.
 */
final class ToolArgumentColoredFormatter
{
    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<string> ANSI-colored lines (no leading indent; caller adds card indent)
     */
    public function formatColoredLines(array $arguments, TuiTheme $theme): array
    {
        if ([] === $arguments) {
            return [];
        }

        $yaml = trim(Yaml::dump(
            $arguments,
            4,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
        ));

        if ('' === $yaml) {
            return [];
        }

        $lines = [];
        foreach (explode("\n", $yaml) as $line) {
            $lines[] = $this->colorYamlLine($line, $theme);
        }

        return $lines;
    }

    private function colorYamlLine(string $line, TuiTheme $theme): string
    {
        if (preg_match('/^(\s*)([^:]+):(.*)$/u', $line, $matches)) {
            $indent = $matches[1];
            $key = $matches[2];
            $rest = $matches[3];

            return $indent
                .$theme->muted($key)
                .':'
                .$theme->text($rest);
        }

        return $theme->text($line);
    }
}
