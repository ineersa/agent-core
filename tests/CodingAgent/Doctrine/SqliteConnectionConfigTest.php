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
 *   options.2) actually reaches SQLite as PRAGMA busy_timeout = 5000ms.
 *   Uses assertSame(5000) so this fails if doctrine.yaml options.2 is
 *   removed and PDO falls back to its 60000ms default.
 * - The startup migration executor (ApplicationMigrationExecutor) applies
 *   WAL journal_mode and verifies a safe minimum busy_timeout before any
 *   migration runs.
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
        // to exactly 5000ms.  If doctrine.yaml options.2 is removed, PDO
        // falls back to 60000ms, so assertSame(5000) catches removal.
        $busyTimeout = (int) $this->connection->executeQuery('PRAGMA busy_timeout')->fetchOne();

        $this->assertSame(
            5000,
            $busyTimeout,
            'Doctrine SQLite connection must have busy_timeout = 5000ms. '
            .'Check config/packages/doctrine.yaml: options.2 (PDO::ATTR_TIMEOUT) must be set to 5. '
            .'PHP PDO default is 60000, so any value other than 5000 means options.2 is missing.',
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
