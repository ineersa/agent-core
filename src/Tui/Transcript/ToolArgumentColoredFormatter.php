<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Yaml\Yaml;

/**
 * Theme-backed key/value coloring for tool argument YAML lines (display only).
 */
final class ToolArgumentColoredFormatter
{
    /** @var array<string, list<string>> */
    private array $coloredLinesCache = [];

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

        $cacheKey = hash('xxh128', serialize($arguments).$theme->getPalette()->name);
        if (isset($this->coloredLinesCache[$cacheKey])) {
            return $this->coloredLinesCache[$cacheKey];
        }

        $yaml = trim(Yaml::dump(
            $arguments,
            4,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
        ));

        if ('' === $yaml) {
            $this->coloredLinesCache[$cacheKey] = [];

            return [];
        }

        $lines = [];
        foreach (explode("\n", $yaml) as $line) {
            $lines[] = $this->colorYamlLine($line, $theme);
        }

        $this->coloredLinesCache[$cacheKey] = $lines;

        return $lines;
    }

    private function colorYamlLine(string $line, TuiTheme $theme): string
    {
        if (preg_match('/^(\s*)([^:]+):(.*)$/u', $line, $matches)) {
            $indent = $matches[1];
            $key = $matches[2];
            $rest = $matches[3];

            return $indent
                .$theme->color(ThemeColorEnum::ToolArgumentKey, $key)
                .':'
                .$theme->color(ThemeColorEnum::ToolArgumentValue, $rest);
        }

        return $theme->color(ThemeColorEnum::ToolArgumentValue, $line);
    }
}
