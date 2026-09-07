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

    public function testExistingMessengerTableAndIndexWithRowsRecordsVersionWithoutLosingData(): void
    {
        $connection = $this->createSqliteConnection($this->isolatedDir.'/preexisting-transport.sqlite');
        $connection->executeStatement(
            'CREATE TABLE messenger_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                body CLOB NOT NULL,
                headers CLOB NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL
            )'
        );
        $connection->executeStatement(
            'CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750
             ON messenger_messages (queue_name, available_at, delivered_at, id)'
        );
        $connection->insert('messenger_messages', [
            'body' => 'keep-me',
            'headers' => '{}',
            'queue_name' => 'agent',
            'created_at' => '2026-09-06 00:00:00',
            'available_at' => '2026-09-06 00:00:00',
            'delivered_at' => null,
        ]);

        $migration = \DoctrineMigrations\MessengerTransport\Version20260828224203::class;
        $executor = new ApplicationMigrationExecutor($connection, new NullLogger(), [$migration]);
        $executor();

        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        $this->assertSame('keep-me', $connection->fetchOne('SELECT body FROM messenger_messages'));
        $this->assertNotFalse($connection->fetchOne(
            'SELECT 1 FROM doctrine_migration_versions WHERE version = ?',
            ['Version20260828224203'],
        ));

        (new ApplicationMigrationExecutor($connection, new NullLogger(), [$migration]))();

        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
        $indexNames = array_keys($connection->createSchemaManager()->listTableIndexes('messenger_messages'));
        $this->assertContains('idx_75ea56e0fb7336f0e3bd61ce16ba31dbbf396750', $indexNames);
    }

    public function testConsoleRecordedTransportFqcnIsRecognizedOnStartup(): void
    {
        $connection = $this->createSqliteConnection($this->isolatedDir.'/console-recorded.sqlite');
        $migration = \DoctrineMigrations\MessengerTransport\Version20260828224203::class;
        $executor = new ApplicationMigrationExecutor($connection, new NullLogger(), [$migration]);
        $executor();

        // Doctrine's console stores the complete migration class as the version.
        $connection->update(
            'doctrine_migration_versions',
            ['version' => $migration],
            [],
        );

        (new ApplicationMigrationExecutor($connection, new NullLogger(), [$migration]))();

        $this->assertTrue($connection->createSchemaManager()->tablesExist(['messenger_messages']));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
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
