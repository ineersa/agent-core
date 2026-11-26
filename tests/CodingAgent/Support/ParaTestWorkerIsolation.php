<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

/**
 * Names SQLite / cache resources for ParaTest workers.
 *
 * castor check runs multiple ParaTest pools in parallel (unit and TUI).
 * Each pool reuses TEST_TOKEN values starting at 1, so paths must include the
 * lane as well as the QA run id and worker token. Otherwise unit T1 and TUI T1
 * share one app_test-*-T1.sqlite and contend on SQLite writes.
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

    public static function cacheDirectory(string $qaRunId, string $lane, string $token): string
    {
        $stem = self::resourceStem($qaRunId, $lane, $token);

        return '' !== $qaRunId || '' !== $lane
            ? '.hatfield/cache-'.$stem
            : '.hatfield/cache-paraT'.self::sanitizeSegment($token);
    }

    /** Each process owns its mutable HOME, including built-in skill extraction. */
    public static function createHome(string $templateHome): string
    {
        $home = TestDirectoryIsolation::createProjectTempDir('qa-worker-home');
        $temporaryRoot = realpath(ProjectDir::get().'/var/tmp');
        $resolvedHome = realpath($home);
        if (false === $temporaryRoot || false === $resolvedHome || !str_starts_with($resolvedHome, $temporaryRoot.'/')) {
            throw new \RuntimeException('Worker HOME must resolve inside the disposable project temporary directory.');
        }
        TestDirectoryIsolation::createHatfieldTree($resolvedHome);
        $template = $templateHome.'/.hatfield/settings.yaml';
        if ('' !== $templateHome && is_file($template) && !copy($template, $resolvedHome.'/.hatfield/settings.yaml')) {
            throw new \RuntimeException('Unable to copy isolated QA settings into worker HOME.');
        }

        return $resolvedHome;
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
