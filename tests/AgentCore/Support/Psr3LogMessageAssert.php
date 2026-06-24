<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

/**
 * Normalizes PSR-3 log messages when ddtrace/Monolog processors append metadata.
 *
 * Datadog log injection can suffix messages with bracketed fields such as
 * [dd.trace_id="..." dd.span_id="..." ...] while preserving the stable
 * event prefix (e.g. agent_loop.trace.start).
 */
final class Psr3LogMessageAssert
{
    public static function normalize(string $message): string
    {
        $trimmed = rtrim($message);

        if (preg_match('/^(.*?)(?:\s+\[dd\.trace_id=.*\])+$/s', $trimmed, $matches)) {
            return $matches[1];
        }

        return $trimmed;
    }

    public static function isEvent(string $message, string $event): bool
    {
        return self::normalize($message) === $event;
    }
}
