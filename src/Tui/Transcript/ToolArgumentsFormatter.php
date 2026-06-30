<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Symfony\Component\Yaml\Yaml;

/**
 * Formats tool-call argument arrays for compact transcript cards (display only).
 */
final class ToolArgumentsFormatter
{
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

        $yaml = trim(Yaml::dump(
            $arguments,
            4,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
        ));

        if ('' === $yaml) {
            return [];
        }

        /* @var list<string> */
        return explode("\n", $yaml);
    }
}
