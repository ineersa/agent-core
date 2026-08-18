<?php

declare(strict_types=1);

namespace Ineersa\Tui\Utility;

/**
 * Shared throwable → short user/log-safe message for TUI slash handlers.
 */
final class ThrowableMessage
{
    public static function sanitize(\Throwable $e): string
    {
        $trimmed = trim($e->getMessage());
        if ('' === $trimmed) {
            return $e::class;
        }
        $firstLine = explode("\n", $trimmed, 2)[0];
        $bounded = mb_strlen($firstLine) > 200 ? mb_substr($firstLine, 0, 200).'…' : $firstLine;

        return $e::class.': '.$bounded;
    }
}
