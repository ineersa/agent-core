<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Migrations;

use Doctrine\DBAL\DriverManager;
use Ineersa\CodingAgent\Migrations\ApplicationMigrationExecutor;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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

        self::assertTrue(
            $connection->createSchemaManager()->tablesExist(['messenger_messages']),
            'messenger_messages must exist after runtime startup migrations (Version20260617141000)',
        );

        $recorded = $connection->fetchOne(
            'SELECT 1 FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260617141000'],
        );
        self::assertNotFalse(
            $recorded,
            'Version20260617141000 must be recorded in doctrine_migration_versions',
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
        self::assertSame(1, $count);
    }
}
