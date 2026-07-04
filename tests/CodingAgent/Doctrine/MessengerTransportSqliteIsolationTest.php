<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ineersa\CodingAgent\Migrations\MessengerTransportSchemaEnsurer;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;

/**
 * Proves Symfony boots two distinct SQLite DBAL connections for app state vs
 * Messenger transport, each with WAL + busy_timeout=5000 via Doctrine.
 *
 * @requires extension pdo_sqlite
 *
 * @coversNothing
 */
final class MessengerTransportSqliteIsolationTest extends IsolatedKernelTestCase
{
    private Connection $defaultConnection;

    private Connection $transportConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultConnection = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->transportConnection = static::getContainer()->get('doctrine.dbal.messenger_transport_connection');
    }

    public function testDefaultAndMessengerTransportUseDifferentSqliteFiles(): void
    {
        $defaultPath = $this->sqliteFilePath($this->defaultConnection);
        $transportPath = $this->sqliteFilePath($this->transportConnection);

        $this->assertNotSame($defaultPath, $transportPath);
        $this->assertStringContainsString('messenger_transport', $transportPath);
        $this->assertStringNotContainsString('messenger_transport', $defaultPath);
    }

    public function testBothConnectionsHaveBusyTimeoutConfigured(): void
    {
        foreach ([$this->defaultConnection, $this->transportConnection] as $connection) {
            $busyTimeout = (int) $connection->executeQuery('PRAGMA busy_timeout')->fetchOne();
            $this->assertSame(5000, $busyTimeout, 'Connection '.$this->connectionLabel($connection));
        }
    }

    public function testTransportWriteSucceedsWhileDefaultConnectionHoldsWriteTransaction(): void
    {
        $transportPath = $this->sqliteFilePath($this->transportConnection);
        $queueName = 'isolation_test_'.bin2hex(random_bytes(8));

        // DAMA wraps the kernel DBAL connections in a test transaction, so WAL
        // setup and queue writes use a fresh connection to the same transport file.
        $freshTransport = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $transportPath,
            'driverOptions' => [\PDO::ATTR_TIMEOUT => 5],
        ]);
        (new MessengerTransportSchemaEnsurer($freshTransport, new NullLogger()))();

        $this->defaultConnection->beginTransaction();
        $this->defaultConnection->executeStatement(
            'CREATE TABLE IF NOT EXISTS isolation_probe (id INTEGER PRIMARY KEY)',
        );
        $this->defaultConnection->executeStatement('INSERT INTO isolation_probe (id) VALUES (1)');

        try {
            $freshTransport->executeStatement(
                "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at)
                 VALUES ('body', '[]', ?, datetime('now'), datetime('now'))",
                [$queueName],
            );

            $count = (int) $freshTransport->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?',
                [$queueName],
            );
            $this->assertSame(1, $count);
        } finally {
            $this->defaultConnection->rollBack();
            $freshTransport->executeStatement(
                'DELETE FROM messenger_messages WHERE queue_name = ?',
                [$queueName],
            );
            $freshTransport->close();
        }
    }

    private function sqliteFilePath(Connection $connection): string
    {
        $params = $connection->getParams();
        $path = $params['path'] ?? null;
        $this->assertIsString($path);

        return $path;
    }

    private function connectionLabel(Connection $connection): string
    {
        return $connection === $this->defaultConnection ? 'default' : 'messenger_transport';
    }
}