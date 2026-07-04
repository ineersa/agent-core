<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Psr\Log\LoggerInterface;

/**
 * Ensures the Symfony Messenger Doctrine transport table exists on the
 * dedicated messenger_transport DBAL connection.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Queue rows (messenger_messages) live in .hatfield/messenger-transport.sqlite,
 * separate from app/runtime state in .hatfield/messenger.sqlite. Startup
 * migrations on the default connection must not be required for transport
 * persistence — otherwise app-state write locks can still block queue setup.
 *
 * The DDL matches Version20260617141000 and
 * Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection::configureSchemaTable()
 * so concurrent messenger:consume workers do not race auto_setup.
 *
 * @see ApplicationMigrationExecutor  App entity schema + doctrine_migration_versions
 * @see StartupDatabaseMigrator     Invokes this after default-connection migrations
 */
final class MessengerTransportSchemaEnsurer
{
    private bool $ran = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Create messenger_messages (if missing) and apply SQLite hardening on the
     * transport connection. Safe to call multiple times per process.
     */
    public function __invoke(): void
    {
        if ($this->ran) {
            return;
        }

        $this->ran = true;

        $this->applySqliteHardening();
        $this->ensureMessengerMessagesTable();
    }

    private function applySqliteHardening(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }

        $params = $this->connection->getParams();
        $isMemory = true === ($params['memory'] ?? false);

        if (!$isMemory) {
            $result = $this->connection->executeQuery('PRAGMA journal_mode=WAL')->fetchOne();
            $resultMode = \is_string($result) ? strtolower($result) : '';

            if ('wal' !== $resultMode) {
                throw new \RuntimeException(\sprintf('Failed to set messenger transport SQLite journal_mode to WAL. Expected "wal", got "%s".', $resultMode));
            }
        }

        $busyTimeout = (int) $this->connection->executeQuery('PRAGMA busy_timeout')->fetchOne();

        if ($busyTimeout < 5000) {
            throw new \RuntimeException(\sprintf('Messenger transport SQLite busy_timeout is %dms, expected >= 5000ms. Check doctrine.yaml messenger_transport options.2 (PDO::ATTR_TIMEOUT).', $busyTimeout));
        }
    }

    private function ensureMessengerMessagesTable(): void
    {
        if ($this->connection->createSchemaManager()->tablesExist(['messenger_messages'])) {
            return;
        }

        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS messenger_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            body CLOB NOT NULL,
            headers CLOB NOT NULL,
            queue_name VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL,
            available_at DATETIME NOT NULL,
            delivered_at DATETIME DEFAULT NULL
        )');
        $this->connection->executeStatement(
            'CREATE INDEX IF NOT EXISTS IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)',
        );

        $this->logger->info('messenger_transport.schema.ready', [
            'component' => 'messenger_transport',
            'event_type' => 'messenger_transport.schema.ready',
        ]);
    }
}
