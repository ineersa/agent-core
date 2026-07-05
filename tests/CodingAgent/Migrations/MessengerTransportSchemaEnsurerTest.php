<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ineersa\CodingAgent\Migrations\MessengerTransportSchemaEnsurer;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Ineersa\CodingAgent\Migrations\MessengerTransportSchemaEnsurer
 */
final class MessengerTransportSchemaEnsurerTest extends TestCase
{
    private string $isolatedDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedDir = TestDirectoryIsolation::createProjectTempDir('transport-ensurer-test');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->isolatedDir);
        parent::tearDown();
    }

    public function testCreatesMessengerMessagesOnTransportDatabase(): void
    {
        $connection = $this->createSqliteConnection($this->isolatedDir.'/transport.sqlite');
        $ensurer = new MessengerTransportSchemaEnsurer($connection, new NullLogger());

        $ensurer();

        $this->assertTrue(
            $connection->createSchemaManager()->tablesExist(['messenger_messages']),
        );

        $journalMode = strtolower((string) $connection->executeQuery('PRAGMA journal_mode')->fetchOne());
        $this->assertSame('wal', $journalMode);
    }

    public function testEnsurerIsIdempotentPerProcess(): void
    {
        $connection = $this->createSqliteConnection($this->isolatedDir.'/idempotent.sqlite');
        $ensurer = new MessengerTransportSchemaEnsurer($connection, new NullLogger());

        $ensurer();
        $ensurer();

        $count = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'messenger_messages'",
        );
        $this->assertSame(1, $count);
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
