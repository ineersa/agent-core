<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

/**
 * Normalizes PSR-3 log messages when processors or extensions append metadata.
 *
 * Some environments suffix the application event with one or more trailing
 * bracketed context blocks (e.g. " event.name [key=value ...]") while keeping
 * the stable event prefix unchanged. Tests assert the prefix, not the suffix.
 */
final class Psr3LogMessageAssert
{
    public static function normalize(string $message): string
    {
        $trimmed = rtrim($message);

        if (preg_match('/^(.*?)(?:\s+\[[^\]]*\])+$/s', $trimmed, $matches)) {
            return $matches[1];
        }

        return $trimmed;
    }

    public static function isEvent(string $message, string $event): bool
    {
        return self::normalize($message) === $event;
    }
}
