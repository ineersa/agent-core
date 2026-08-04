<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Utility;

/**
 * Split a comma-separated scalar into trimmed non-empty items.
 *
 * Preserves order and duplicates. Does not apply domain-specific deduplication.
 *
 * @internal
 */
final class CommaSeparatedListParser
{
    /**
     * @return list<string>
     */
    public static function parse(string $value): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $part): bool => '' !== $part,
        ));
    }
}
