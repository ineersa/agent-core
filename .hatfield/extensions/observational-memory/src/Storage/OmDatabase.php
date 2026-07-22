<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Standalone DBAL connection for the OM SQLite database.
 *
 * Explicitly independent of Hatfield Doctrine connections.
 */
final class OmDatabase
{
    private function __construct(
        private readonly Connection $connection,
        private readonly string $path,
    ) {
    }

    public static function connect(string $absolutePath): self
    {
        $dir = \dirname($absolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Unable to create OM data directory: %s', $dir));
        }

        // Explicit driver/path params — DBAL 4 no longer requires a URL, and
        // path-based construction keeps the private OM connection independent
        // of any DoctrineBundle connection registry.
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $absolutePath,
            'driverOptions' => [
                \PDO::ATTR_TIMEOUT => 5,
            ],
        ]);

        $self = new self($connection, $absolutePath);
        $self->applySqliteHardening();

        return $self;
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function path(): string
    {
        return $this->path;
    }

    private function applySqliteHardening(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }

        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $mode = $this->connection->executeQuery('PRAGMA journal_mode=WAL')->fetchOne();
        $modeString = \is_string($mode) ? strtolower($mode) : '';
        if ('wal' !== $modeString) {
            throw new \RuntimeException(\sprintf('Failed to set OM SQLite journal_mode to WAL (got "%s").', $modeString));
        }

        $this->connection->executeStatement('PRAGMA busy_timeout = 5000');
    }
}
