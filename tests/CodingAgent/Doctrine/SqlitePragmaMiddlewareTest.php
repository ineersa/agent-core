<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Connection as PDOConnection;
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
 * synchronous=NORMAL is verified through a standalone connection because
 * SQLite rejects changing the safety level inside a transaction, and DAMA
 * wraps each test in one.
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
            'Expected WAL journal mode; got "%s". '
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
            'Expected busy_timeout >= 5000ms; got %d.',
        );
    }

    public function testMiddlewareAppliesExpectedPragmas(): void
    {
        // Cannot verify synchronous=NORMAL through the DAMA-wrapped
        // container connection because SQLite rejects changing
        // synchronous setting inside a transaction ("Safety level may
        // not be changed inside a transaction"). DAMA wraps each test
        // in a transaction for rollback isolation.
        //
        // Instead, test the middleware directly: create a real
        // PDO SQLite connection through the middleware on a temp file
        // and verify all three PRAGMAs are applied. A file-based path
        // is required because WAL mode is not supported on :memory:
        // databases (WAL needs .sqlite-wal and .sqlite-shm files).
        $tmpDir = TestDirectoryIsolation::createOsTempDir('mw-test');
        $dbPath = $tmpDir.'/test.sqlite';

        try {
            $middleware = new SqlitePragmaMiddleware();
            $innerDriver = new SqlitePdoDriver();
            $wrappedDriver = $middleware->wrap($innerDriver);

            /** @var PDOConnection $connection */
            $connection = $wrappedDriver->connect(['path' => $dbPath]);

            // journal_mode=WAL (file-based DB required)
            $journalMode = $connection->query('PRAGMA journal_mode')->fetchOne();
            $this->assertSame('wal', strtolower((string) $journalMode));

            // busy_timeout=5000
            $busyTimeout = $connection->query('PRAGMA busy_timeout')->fetchOne();
            $this->assertGreaterThanOrEqual(5000, (int) $busyTimeout);

            // synchronous=NORMAL = 1 (NOT verifiable through DAMA because
            // SQLite rejects changing safety level inside a transaction)
            $synchronous = $connection->query('PRAGMA synchronous')->fetchOne();
            $this->assertSame(1, (int) $synchronous, 'Expected synchronous=NORMAL (1); got %d.');
        } finally {
            TestDirectoryIsolation::removeDirectory($tmpDir);
        }
    }
}
