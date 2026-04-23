<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Utils;

final class StringUtils
{
    private function __construct()
    {
    }

    public static function normalizeNullable(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return '' === $normalized ? null : $normalized;
    }
}
