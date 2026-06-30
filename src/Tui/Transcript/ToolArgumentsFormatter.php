<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Symfony\Component\Yaml\Yaml;

/**
 * Formats tool-call argument arrays for compact transcript cards (display only).
 */
final class ToolArgumentsFormatter
{
    /** @var array<string, list<string>> */
    private array $formatLinesCache = [];

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    public function formatLines(array $arguments): array
    {
        if ([] === $arguments) {
            return [];
        }

        $cacheKey = hash('xxh128', serialize($arguments));
        if (isset($this->formatLinesCache[$cacheKey])) {
            return $this->formatLinesCache[$cacheKey];
        }

        $yaml = trim(Yaml::dump(
            $arguments,
            4,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
        ));

        if ('' === $yaml) {
            $this->formatLinesCache[$cacheKey] = [];

            return [];
        }

        $lines = explode("\n", $yaml);
        $this->formatLinesCache[$cacheKey] = $lines;

        return $lines;
    }
}
