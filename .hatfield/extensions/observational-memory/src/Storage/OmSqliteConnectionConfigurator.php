<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

/**
 * Applies OM SQLite PRAGMAs once per package console process.
 */
final class OmSqliteConnectionConfigurator
{
    private bool $configured = false;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        unset($event);
        $this->configure();
    }

    public function configure(): void
    {
        if ($this->configured) {
            return;
        }
        $this->configured = true;

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
