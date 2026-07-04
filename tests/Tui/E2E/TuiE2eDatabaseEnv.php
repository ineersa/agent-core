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
}
