<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/**
 * @covers \Ineersa\CodingAgent\Doctrine\SqlitePragmaMiddleware
 *
 * @requires extension pdo_sqlite
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
        // The middleware sets journal_mode=WAL at connect time
        $journalMode = $this->connection->fetchOne('PRAGMA journal_mode');
        // PRAGMA journal_mode returns the current journal mode string
        // (lowercase, e.g. 'wal', 'delete', 'memory')
        $this->assertSame(
            'wal',
            strtolower((string) $journalMode),
            'Expected WAL journal mode; got "%s". '
            . 'SqlitePragmaMiddleware may not have been applied or the '
            . 'connection was reused from a prior test without middleware.',
        );
    }

    public function testBusyTimeoutIsSet(): void
    {
        $busyTimeout = $this->connection->fetchOne('PRAGMA busy_timeout');
        // busy_timeout returns milliseconds; our value is 5000
        $this->assertGreaterThanOrEqual(
            5000,
            (int) $busyTimeout,
            'Expected busy_timeout >= 5000ms; got %d.',
        );
    }

    public function testSynchronousIsNormal(): void
    {
        $synchronous = $this->connection->fetchOne('PRAGMA synchronous');
        // synchronous=2 means NORMAL
        $this->assertSame(
            2,
            (int) $synchronous,
            'Expected synchronous=NORMAL (2); got %d.',
        );
    }
}
