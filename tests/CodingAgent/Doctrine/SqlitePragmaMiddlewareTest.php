<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SqlitePdoDriver;
use Ineersa\CodingAgent\Doctrine\SqlitePragmaMiddleware;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/**
 * @covers \Ineersa\CodingAgent\Doctrine\SqlitePragmaMiddleware
 *
 * @requires extension pdo_sqlite
 *
 * WAL and busy_timeout are verified through the integration test connection
 * (DAMA-wrapped, which allows both PRAGMAs inside transactions).
 * The standalone connection test verifies both PRAGMAs on a fresh connection.
 */
final class SqlitePragmaMiddlewareTest extends IsolatedKernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = static::getContainer()->get('doctrine.dbal.default_connection');
    }

    public function testConnectionIsUsable(): void
    {
        // After middleware application, basic queries must still work
        $version = $this->connection->fetchOne('SELECT sqlite_version()');
        $this->assertNotNull($version);
        $this->assertStringContainsString('.', (string) $version);
    }

    public function testJournalModeIsWal(): void
    {
        // The middleware sets journal_mode=WAL at connect time.
        // WAL can be changed inside a transaction, so this works on the
        // DAMA-wrapped container connection.
        $journalMode = $this->connection->fetchOne('PRAGMA journal_mode');
        // PRAGMA journal_mode returns the current journal mode string
        // (lowercase, e.g. 'wal', 'delete', 'memory')
        $this->assertSame(
            'wal',
            strtolower((string) $journalMode),
            'Expected WAL journal mode. '
            .'SqlitePragmaMiddleware may not have been applied or the '
            .'connection was reused from a prior test without middleware.',
        );
    }

    public function testBusyTimeoutIsSet(): void
    {
        // busy_timeout can be changed inside a transaction, so this works
        // on the DAMA-wrapped container connection.
        $busyTimeout = $this->connection->fetchOne('PRAGMA busy_timeout');
        // busy_timeout returns milliseconds; our value is 5000
        $this->assertGreaterThanOrEqual(
            5000,
            (int) $busyTimeout,
            'Expected busy_timeout >= 5000ms.',
        );
    }

    public function testMiddlewareAppliesExpectedPragmas(): void
    {
        // Test the middleware directly: create a real PDO SQLite connection
        // through the middleware on a temp file and verify the critical
        // PRAGMAs (WAL, busy_timeout) are applied.
        //
        // A file-based path is required because WAL mode is not supported
        // on :memory: databases (WAL needs .sqlite-wal and .sqlite-shm).
        //
        // synchronous=NORMAL is deliberately NOT tested here — it is already
        // the default in WAL mode on modern SQLite, and SQLite rejects
        // changing it inside a transaction (DAMA isolation).  It was
        // removed from the middleware PRAGMA list to avoid confusion.
        $tmpDir = TestDirectoryIsolation::createOsTempDir('mw-test');

        try {
            $middleware = new SqlitePragmaMiddleware();
            $innerDriver = new SqlitePdoDriver();
            $wrappedDriver = $middleware->wrap($innerDriver);

            $dbPath = $tmpDir.'/test.sqlite';
            $connection = $wrappedDriver->connect(['path' => $dbPath]);

            // journal_mode=WAL (file-based DB required; :memory: falls back to 'memory')
            $journalMode = $connection->query('PRAGMA journal_mode')->fetchOne();
            $this->assertSame('wal', strtolower((string) $journalMode));

            // busy_timeout=5000
            $busyTimeout = $connection->query('PRAGMA busy_timeout')->fetchOne();
            $this->assertGreaterThanOrEqual(5000, (int) $busyTimeout);
        } finally {
            TestDirectoryIsolation::removeDirectory($tmpDir);
        }
    }
}
