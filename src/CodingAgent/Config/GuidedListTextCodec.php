<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lossless YAML-sequence encoding for guided list fields in extensions:setup.
 *
 * Keeps commas, quotes, backslashes, and significant spaces inside list entries
 * intact. Rejects ambiguous delimiter text and non-string entries instead of
 * guessing or silently coercing shapes.
 */
final class GuidedListTextCodec
{
    /**
     * @param list<mixed>|array<mixed> $values
     */
    public static function format(array $values): string
    {
        return trim(Yaml::dump(
            self::stringList($values),
            0,
            4,
            Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE,
        ));
    }

    /**
     * @return list<string>
     */
    public static function parse(mixed $value): array
    {
        if (\is_array($value)) {
            return self::stringList($value);
        }

        $raw = (string) $value;
        if ('' === trim($raw)) {
            return [];
        }

        try {
            $parsed = Yaml::parse($raw);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException('List fields must be a YAML sequence (example: [\'echo hello, world\', /tmp/extra]). '.$e->getMessage(), 0, $e);
        }

        if (!\is_array($parsed) || !array_is_list($parsed)) {
            throw new \InvalidArgumentException('List fields must be a YAML sequence (example: [\'echo hello, world\', /tmp/extra]).');
        }

        return self::stringList($parsed);
    }

    /**
     * Preserve the original list when the displayed YAML text is unchanged.
     *
     * @param list<mixed>|array<mixed> $original
     *
     * @return list<string>
     */
    public static function parseEdited(string $value, array $original): array
    {
        $originalList = self::stringList($original);
        if ($value === self::format($originalList)) {
            return $originalList;
        }

        return self::parse($value);
    }

    /**
     * @param list<mixed>|array<mixed> $values
     *
     * @return list<string>
     */
    private static function stringList(array $values): array
    {
        if (!array_is_list($values)) {
            throw new \InvalidArgumentException('List fields must be a YAML sequence (example: [\'echo hello, world\', /tmp/extra]).');
        }

        $out = [];
        foreach ($values as $item) {
            if (!\is_string($item)) {
                throw new \InvalidArgumentException('List fields must contain only string entries.');
            }
            $out[] = $item;
        }

        return $out;
    }
}
