<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Support;

/**
 * Canonical JSON + SHA-256 helpers for OM identity formulas.
 */
final class OmCanonicalJson
{
    public const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    /**
     * @param array<mixed>|list<mixed> $value
     */
    public static function encode(array $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }

    /**
     * @param array<mixed>|list<mixed> $value
     */
    public static function sha256(array $value): string
    {
        return strtolower(hash('sha256', self::encode($value)));
    }

    public static function sha256Bytes(string $bytes): string
    {
        return strtolower(hash('sha256', $bytes));
    }
}
