<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

/**
 * Isolated SQLite paths for TUI E2E subprocesses (app state + Messenger transport).
 *
 * TUI tmux tests spawn agent/controller children that do not inherit ParaTest
 * worker env; each test must pass both HATFIELD_TEST_* paths explicitly so
 * parallel TUI workers do not share one messenger_transport_test.sqlite.
 */
final class TuiE2eDatabaseEnv
{
    /**
     * @return array{app: string, transport: string}
     */
    public static function allocatePaths(string $prefix): array
    {
        $suffix = $prefix.'-'.bin2hex(random_bytes(4));

        return [
            'app' => 'app_test-'.$suffix.'.sqlite',
            'transport' => 'messenger_transport_test-'.$suffix.'.sqlite',
        ];
    }

    public static function shellPrefix(string $appDbPath, string $transportDbPath): string
    {
        return \sprintf(
            'HATFIELD_TEST_DATABASE_PATH=%s HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH=%s ',
            escapeshellarg($appDbPath),
            escapeshellarg($transportDbPath),
        );
    }
    /**
     * Derive paired transport filename from an app_test-*.sqlite basename.
     */
    public static function transportPathForAppBasename(string $appBasename): string
    {
        if (str_starts_with($appBasename, 'app_test-')) {
            return 'messenger_transport_test-'.substr($appBasename, \strlen('app_test-'));
        }

        return 'messenger_transport_'.$appBasename;
    }

    /**
     * @return array{app: string, transport: string}
     */
    public static function allocatePathsFromAppBasename(string $appBasename): array
    {
        return [
            'app' => $appBasename,
            'transport' => self::transportPathForAppBasename($appBasename),
        ];
    }

    /**
     * Prefix for tmux agent shell commands: APP_ENV=test plus paired DB env vars.
     */
    public static function agentShellPrefix(string $appDbPath, string $transportDbPath): string
    {
        return 'APP_ENV=test '.self::shellPrefix($appDbPath, $transportDbPath);
    }

}
