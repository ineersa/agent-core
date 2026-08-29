<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Applies SQLite connection settings required before application or Messenger
 * migrations run on a file-backed connection.
 */
final class SqliteConnectionHardener
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function apply(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }

        $params = $this->connection->getParams();
        $isMemory = true === ($params['memory'] ?? false);

        // SQLite rejects journal_mode changes inside the transaction opened by
        // DoctrineTestBundle. Runtime startup reaches this before work begins.
        if (!$isMemory && !$this->connection->isTransactionActive()) {
            $journalMode = $this->connection->executeQuery('PRAGMA journal_mode=WAL')->fetchOne();
            $resultMode = \is_string($journalMode) ? strtolower($journalMode) : '';

            if ('wal' !== $resultMode) {
                throw new \RuntimeException(\sprintf('Failed to set SQLite journal_mode to WAL. Expected "wal", got "%s".', $resultMode));
            }
        }

        $busyTimeout = (int) $this->connection->executeQuery('PRAGMA busy_timeout')->fetchOne();

        if ($busyTimeout < 5000) {
            throw new \RuntimeException(\sprintf('SQLite busy_timeout is %dms, expected >= 5000ms. Check doctrine.yaml options.2 (PDO::ATTR_TIMEOUT).', $busyTimeout));
        }
    }
}
