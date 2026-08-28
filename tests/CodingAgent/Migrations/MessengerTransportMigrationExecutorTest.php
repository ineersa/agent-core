<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ineersa\CodingAgent\Migrations\ApplicationMigrationExecutor;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The transport executor must use only the generated Messenger migration; it
 * cannot provision application tables or rely on Messenger auto_setup.
 */
final class MessengerTransportMigrationExecutorTest extends TestCase
{
    private string $isolatedDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedDir = TestDirectoryIsolation::createProjectTempDir('transport-migration-executor-test');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->isolatedDir);
        parent::tearDown();
    }

    public function testGeneratedTransportMigrationCreatesOnlyMessengerStorage(): void
    {
        $connection = $this->createSqliteConnection($this->isolatedDir.'/transport.sqlite');
        $executor = new ApplicationMigrationExecutor(
            $connection,
            new NullLogger(),
            [\DoctrineMigrations\MessengerTransport\Version20260828224203::class],
        );

        $executor();

        $schemaManager = $connection->createSchemaManager();
        $this->assertTrue($schemaManager->tablesExist(['messenger_messages']));
        $this->assertFalse($schemaManager->tablesExist(['run_operational_state', 'hatfield_session']));
        $this->assertNotFalse($connection->fetchOne(
            'SELECT 1 FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260828224203'],
        ));
    }

    private function createSqliteConnection(string $dbPath): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $dbPath,
            'driverOptions' => [\PDO::ATTR_TIMEOUT => 5],
        ]);
    }
}
