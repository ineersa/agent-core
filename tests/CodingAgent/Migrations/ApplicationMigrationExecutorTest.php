<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Migrations;

use Doctrine\DBAL\DriverManager;
use Ineersa\CodingAgent\Migrations\ApplicationMigrationExecutor;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */

/**
 * Regression: runtime startup uses ApplicationMigrationExecutor's explicit
 * KNOWN_MIGRATIONS list (not filesystem discovery). Omission of
 * Version20260617141000 leaves messenger_messages missing and consumers fail.
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

    public function testEmptySqliteDatabaseGetsMessengerMessagesAfterStartupExecutor(): void
    {
        $dbPath = $this->isolatedDir.'/empty.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $executor = new ApplicationMigrationExecutor($connection, new NullLogger());

        $executor();

        $this->assertTrue(
            $connection->createSchemaManager()->tablesExist(['messenger_messages']),
            'messenger_messages must exist after runtime startup migrations (Version20260617141000)',
        );

        $recorded = $connection->fetchOne(
            'SELECT 1 FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260617141000'],
        );
        $this->assertNotFalse(
            $recorded,
            'Version20260617141000 must be recorded in doctrine_migration_versions',
        );

        // Verify the new background_process index migration was also applied.
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
    }

    public function testStartupExecutorIsIdempotentPerProcess(): void
    {
        $dbPath = $this->isolatedDir.'/idempotent.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $executor = new ApplicationMigrationExecutor($connection, new NullLogger());

        $executor();
        $executor();

        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260617141000'],
        );
        $this->assertSame(1, $count);

        // The new background_process index migration must also be idempotent.
        $countNew = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260628140000'],
        );
        $this->assertSame(1, $countNew);
    }
}
