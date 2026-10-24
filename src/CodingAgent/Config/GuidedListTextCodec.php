<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lossless YAML-sequence encoding for guided list fields in extensions:setup.
 *
 * Keeps commas, quotes, and backslashes inside list entries intact. Rejects
 * ambiguous delimiter text instead of guessing.
 */
final class GuidedListTextCodec
{
    /**
     * @param list<mixed>|array<mixed> $values
     */
    public static function format(array $values): string
    {
        $parts = [];
        foreach ($values as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                $parts[] = trim($value);
            }
        }

        return trim(Yaml::dump($parts, 0, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE));
    }

    /**
     * @return list<string>
     */
    public static function parse(mixed $value): array
    {
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                if (\is_string($item) && '' !== trim($item)) {
                    $out[] = trim($item);
                }
            }

            return $out;
        }

        $raw = trim((string) $value);
        if ('' === $raw) {
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

        $out = [];
        foreach ($parsed as $item) {
            if (!\is_string($item)) {
                throw new \InvalidArgumentException('List fields must contain only string entries.');
            }
            $item = trim($item);
            if ('' !== $item) {
                $out[] = $item;
            }
        }

        return $out;
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
        $normalizedOriginal = self::parse($original);
        if ($value === self::format($normalizedOriginal)) {
            return $normalizedOriginal;
        }

        return self::parse($value);
    }
}
