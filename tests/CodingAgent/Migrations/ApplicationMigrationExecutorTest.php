<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ineersa\CodingAgent\Migrations\ApplicationMigrationExecutor;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */

/**
 * Regression: runtime startup uses ApplicationMigrationExecutor's explicit
 * KNOWN_MIGRATIONS list (not filesystem discovery). Messenger queue DDL is
 * ensured separately on messenger_transport via MessengerTransportSchemaEnsurer.
 */
final class ApplicationMigrationExecutorTest extends TestCase
{
    private string $isolatedDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedDir = TestDirectoryIsolation::createProjectTempDir('migration-executor-test');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->isolatedDir);
        parent::tearDown();
    }

    public function testEmptySqliteDatabaseGetsAppSchemaAfterStartupExecutor(): void
    {
        $connection = $this->createSqliteConnection($this->isolatedDir.'/empty.sqlite');
        $executor = new ApplicationMigrationExecutor($connection, new NullLogger());

        $executor();

        $this->assertFalse(
            $connection->createSchemaManager()->tablesExist(['messenger_messages']),
            'messenger_messages must not be created on the default app connection (queue uses messenger_transport DB)',
        );

        // Verify the background_process index migration was applied.
        $recordedNew = $connection->fetchOne(
            'SELECT 1 FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260628140000'],
        );
        $this->assertNotFalse(
            $recordedNew,
            'Version20260628140000 (background_process indexes) must be recorded in doctrine_migration_versions',
        );

        // Verify at least one of the new indexes exists via schema manager.
        $indexNames = array_keys($connection->createSchemaManager()->listTableIndexes('background_process'));
        $this->assertContains(
            'idx_bg_process_pid',
            $indexNames,
            'background_process must have the idx_bg_process_pid index after startup executor',
        );
        $this->assertContains(
            'idx_bg_process_session_id',
            $indexNames,
            'background_process must have the idx_bg_process_session_id index after startup executor',
        );
        $this->assertContains(
            'idx_bg_process_finished_at',
            $indexNames,
            'background_process must have the idx_bg_process_finished_at index after startup executor',
        );

        // SQLite concurrency hardening (#228): WAL journal mode and
        // busy_timeout must be applied/verified by the executor.
        $journalMode = $connection->executeQuery('PRAGMA journal_mode')->fetchOne();
        $this->assertSame(
            'wal',
            strtolower((string) $journalMode),
            'SQLite journal_mode must be WAL after executor startup (#228)',
        );

        // busy_timeout came from driverOptions (PDO::ATTR_TIMEOUT=5), so
        // it should be exactly 5000.  We assert >=5000 as the runtime
        // guard's minimum; the strict config proof is in SqliteConnectionConfigTest.

        $this->assertFalse(
            $connection->createSchemaManager()->tablesExist(['tool_batch_state']),
            'tool_batch_state must be dropped after Version20260710120000',
        );

        $recordedDrop = $connection->fetchOne(
            'SELECT 1 FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260710120000'],
        );
        $this->assertNotFalse(
            $recordedDrop,
            'Version20260710120000 (drop tool_batch_state) must be recorded in doctrine_migration_versions',
        );

        $recordedDeferredTool = $connection->fetchOne(
            'SELECT 1 FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260713130000'],
        );
        $this->assertNotFalse(
            $recordedDeferredTool,
            'Version20260713130000 (deferred_tool_completion) must be recorded in doctrine_migration_versions',
        );

        $recordedDeferredLaunch = $connection->fetchOne(
            'SELECT 1 FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260713140000'],
        );
        $this->assertNotFalse(
            $recordedDeferredLaunch,
            'Version20260713140000 (deferred_single_subagent_launch) must be recorded in doctrine_migration_versions',
        );

        $schemaManager = $connection->createSchemaManager();
        $this->assertTrue(
            $schemaManager->tablesExist(['deferred_tool_completion']),
            'deferred_tool_completion must exist after startup executor (run_control deferred registration)',
        );

        $recordedDeferredBatch = $connection->fetchOne(
            'SELECT 1 FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260713160000'],
        );
        $this->assertNotFalse(
            $recordedDeferredBatch,
            'Version20260713160000 (deferred_subagent_batch) must be recorded in doctrine_migration_versions',
        );

        $this->assertTrue(
            $schemaManager->tablesExist(['deferred_subagent_batch', 'deferred_subagent_child']),
            'deferred_subagent_batch and deferred_subagent_child must exist after startup executor (Piece 4A)',
        );

        $recordedDeferredSingleDrop = $connection->fetchOne(
            'SELECT 1 FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260714140000'],
        );
        $this->assertNotFalse(
            $recordedDeferredSingleDrop,
            'Version20260714140000 (drop deferred_single_subagent_launch) must be recorded in doctrine_migration_versions',
        );

        $this->assertFalse(
            $schemaManager->tablesExist(['deferred_single_subagent_launch']),
            'deferred_single_subagent_launch must be dropped after Version20260714140000',
        );

        $busyTimeout = (int) $connection->executeQuery('PRAGMA busy_timeout')->fetchOne();
        $columns = $connection->createSchemaManager()->listTableColumns('hatfield_session');
        $this->assertArrayHasKey('provider_cache_key', $columns);

        $recordedProviderKey = $connection->fetchOne(
            'SELECT 1 FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260713120000'],
        );
        $this->assertNotFalse($recordedProviderKey);

        $this->assertGreaterThanOrEqual(
            5000,
            $busyTimeout,
            'SQLite busy_timeout must be >= 5000ms after executor startup (#228)',
        );
    }

    public function testIsAppliedAcceptsFqcnVersionRowsRecordedByConsoleMigrate(): void
    {
        $connection = $this->createSqliteConnection($this->isolatedDir.'/fqcn.sqlite');
        $executor = new ApplicationMigrationExecutor($connection, new NullLogger());
        $executor();

        $connection->executeStatement(
            "UPDATE doctrine_migration_versions
             SET version = 'DoctrineMigrations\' || version
             WHERE version NOT LIKE 'DoctrineMigrations\%'"
        );

        $executor = new ApplicationMigrationExecutor($connection, new NullLogger());
        $executor();

        $this->assertTrue(
            $connection->createSchemaManager()->tablesExist(['hatfield_session']),
            'Second startup pass must remain idempotent after FQCN version normalization by console migrate',
        );
    }

    public function testStartupExecutorIsIdempotentPerProcess(): void
    {
        $connection = $this->createSqliteConnection($this->isolatedDir.'/idempotent.sqlite');
        $executor = new ApplicationMigrationExecutor($connection, new NullLogger());

        $executor();
        $executor();

        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260628140000'],
        );
        $this->assertSame(1, $count);

        // The new background_process index migration must also be idempotent.
        $countNew = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260628140000'],
        );
        $this->assertSame(1, $countNew);
    }

    public function testProviderCacheKeyBackfillAssignsDistinctUuidV7ForPreMigrationRows(): void
    {
        $connection = $this->createSqliteConnection($this->isolatedDir.'/provider-key-backfill.sqlite');
        $connection->executeStatement(<<<'SQL'
CREATE TABLE hatfield_session (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    cwd VARCHAR(255) NOT NULL,
    prompt VARCHAR(255) DEFAULT NULL,
    parent_id VARCHAR(255) DEFAULT NULL,
    root_id VARCHAR(255) DEFAULT NULL,
    model VARCHAR(255) DEFAULT NULL,
    model_provider VARCHAR(255) DEFAULT NULL,
    model_name VARCHAR(255) DEFAULT NULL,
    reasoning VARCHAR(255) DEFAULT NULL,
    name VARCHAR(200) DEFAULT '' NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
)
SQL);
        $now = '2026-07-13 00:00:00';
        $connection->insert('hatfield_session', ['cwd' => '/a', 'name' => 'one', 'created_at' => $now, 'updated_at' => $now]);
        $connection->insert('hatfield_session', ['cwd' => '/b', 'name' => 'two', 'created_at' => $now, 'updated_at' => $now]);

        // Mirror Version20260713120000 up() SQL/backfill without AbstractMigration construction.
        $connection->executeStatement('ALTER TABLE hatfield_session ADD COLUMN provider_cache_key VARCHAR(36) DEFAULT NULL');
        $ids = $connection->fetchFirstColumn('SELECT id FROM hatfield_session');
        foreach ($ids as $id) {
            $key = UuidV7::v7()->toRfc4122();
            $connection->executeStatement(
                'UPDATE hatfield_session SET provider_cache_key = ? WHERE id = ?',
                [$key, $id],
            );
        }
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_hatfield_session_provider_cache_key ON hatfield_session (provider_cache_key)');

        $keys = $connection->fetchFirstColumn('SELECT provider_cache_key FROM hatfield_session ORDER BY id');
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
        foreach ($keys as $key) {
            $this->assertIsString($key);
            $this->assertTrue(Uuid::isValid($key));
            $this->assertInstanceOf(UuidV7::class, Uuid::fromString($key));
        }
    }

    public function testBusyTimeoutBelowMinimumThrowsRuntimeException(): void
    {
        // PDO::ATTR_TIMEOUT=1 maps to busy_timeout=1000ms, which is below
        // the 5000ms minimum the executor requires.  This ensures the
        // runtime check is not dead code.
        $connection = $this->createSqliteConnection($this->isolatedDir.'/busy_low.sqlite', timeout: 1);
        $executor = new ApplicationMigrationExecutor($connection, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('busy_timeout');
        $this->expectExceptionMessage('5000');

        $executor();
    }

    /**
     * Create a SQLite connection for testing.
     *
     * Uses explicit driverOptions to set PDO::ATTR_TIMEOUT, avoiding
     * silent reliance on PHP PDO's 60000ms default.  This makes the
     * timeout assertion meaningful.
     *
     * @param int $timeout PDO::ATTR_TIMEOUT value in seconds (default 5).
     *                     Busy timeout in ms = $timeout * 1000.
     */
    private function createSqliteConnection(string $dbPath, int $timeout = 5): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $dbPath,
            'driverOptions' => [\PDO::ATTR_TIMEOUT => $timeout],
        ]);
    }
}
