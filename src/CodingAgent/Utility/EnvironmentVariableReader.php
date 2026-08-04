<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Utility;

/**
 * Read a process environment value with Symfony-friendly resolution order.
 *
 * Order: string {@see $_ENV}, then string {@see $_SERVER}, then {@see getenv()}.
 * Does not trim values; empty string is returned as-is; missing → null.
 *
 * @internal
 */
final class EnvironmentVariableReader
{
    public static function read(string $name): ?string
    {
        if (\array_key_exists($name, $_ENV) && \is_string($_ENV[$name])) {
            return $_ENV[$name];
        }
        if (\array_key_exists($name, $_SERVER) && \is_string($_SERVER[$name])) {
            return $_SERVER[$name];
        }

        $value = getenv($name);

        return false === $value ? null : $value;
    }
}
