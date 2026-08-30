<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

/**
 * Names SQLite / cache resources for ParaTest workers.
 *
 * castor check runs multiple ParaTest pools in parallel (unit, tui, llm-real).
 * Each pool reuses TEST_TOKEN values starting at 1, so paths must include the
 * lane as well as the QA run id and worker token. Otherwise unit T1, tui T1,
 * and llm-real T1 share one app_test-*-T1.sqlite and contend on SQLite writes.
 */
final class ParaTestWorkerIsolation
{
    public static function sanitizeSegment(string $value): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '', $value) ?? '';

        return '' !== $sanitized ? $sanitized : 'unknown';
    }

    public static function appDatabaseFilename(string $qaRunId, string $lane, string $token): string
    {
        return 'app_test-'.self::resourceStem($qaRunId, $lane, $token).'.sqlite';
    }

    public static function messengerTransportDatabaseFilename(string $qaRunId, string $lane, string $token): string
    {
        return 'messenger_transport_test-'.self::resourceStem($qaRunId, $lane, $token).'.sqlite';
    }

    public static function cacheDirectory(string $qaRunId, string $lane, string $token): string
    {
        $stem = self::resourceStem($qaRunId, $lane, $token);

        return '' !== $qaRunId || '' !== $lane
            ? '.hatfield/cache-'.$stem
            : '.hatfield/cache-paraT'.self::sanitizeSegment($token);
    }

    private static function resourceStem(string $qaRunId, string $lane, string $token): string
    {
        $parts = [];
        if ('' !== $qaRunId) {
            $parts[] = self::sanitizeSegment($qaRunId);
        }
        if ('' !== $lane) {
            $parts[] = self::sanitizeSegment($lane);
        }
        $parts[] = 'T'.self::sanitizeSegment('' !== $token ? $token : '0');

        return implode('-', $parts);
    }
}
