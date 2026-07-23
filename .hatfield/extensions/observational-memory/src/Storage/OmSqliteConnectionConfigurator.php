<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Applies OM SQLite PRAGMAs for concurrent Hatfield workers.
 */
final class OmSqliteConnectionConfigurator
{
    public static function configure(Connection $connection): void
    {
        if (!$connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }

        $connection->executeStatement('PRAGMA foreign_keys = ON');

        $mode = $connection->executeQuery('PRAGMA journal_mode=WAL')->fetchOne();
        $modeString = \is_string($mode) ? strtolower($mode) : '';
        if ('wal' !== $modeString) {
            throw new \RuntimeException(\sprintf('Failed to set OM SQLite journal_mode to WAL (got "%s").', $modeString));
        }

        $connection->executeStatement('PRAGMA busy_timeout = 5000');
    }
}
