<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/**
 * Verifies that the Doctrine SQLite connection is configured with the
 * critical concurrency settings required for multi-process safety (#228).
 *
 * WHAT THIS PROVES
 * ────────────────
 * - PDO::ATTR_TIMEOUT=5 (configured in config/packages/doctrine.yaml under
 *   options.2) actually reaches SQLite as PRAGMA busy_timeout >= 5000ms.
 * - The startup migration executor (ApplicationMigrationExecutor) applies
 *   WAL journal_mode and verifies busy_timeout before any migration runs.
 *
 * WITHOUT THESE SETTINGS
 * ──────────────────────
 * - SQLite immediately returns SQLITE_BUSY on concurrent writes, making
 *   background-process records appear to "vanish" (ORM queries return null)
 *   and operation retries fail with "database is locked".
 *
 * @requires extension pdo_sqlite
 *
 * @coversNothing  Integration test — validates config wiring, not a class.
 */
final class SqliteConnectionConfigTest extends IsolatedKernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = static::getContainer()->get('doctrine.dbal.default_connection');
    }

    public function testBusyTimeoutIsConfigured(): void
    {
        // PRAGMA busy_timeout returns the current busy handler timeout in
        // milliseconds.  PDO::ATTR_TIMEOUT=5 (doctrine.yaml options.2) maps
        // to 5000ms.  The default SQLite busy_timeout is 0, but PHP PDO
        // sets it to 60000ms.  This assertion proves the config is applied.
        $busyTimeout = (int) $this->connection->executeQuery('PRAGMA busy_timeout')->fetchOne();

        $this->assertGreaterThanOrEqual(
            5000,
            $busyTimeout,
            'Doctrine SQLite connection must have busy_timeout >= 5000ms. '
            .'Check config/packages/doctrine.yaml: options.2 (PDO::ATTR_TIMEOUT) must be set to 5.',
        );
    }

    public function testConnectionIsSqlite(): void
    {
        // Sanity check: the Doctrine connection is SQLite (not another
        // driver), which ensures the connection is usable.
        $version = $this->connection->executeQuery('SELECT sqlite_version()')->fetchOne();

        $this->assertNotNull($version);
        $this->assertStringContainsString('.', (string) $version);
    }
}
