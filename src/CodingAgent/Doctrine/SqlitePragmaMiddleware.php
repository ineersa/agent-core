<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL driver middleware that applies SQLite PRAGMAs on every new connection.
 *
 * This addresses the shared SQLite contention in #228 where multiple
 * processes (controller, Messenger consumers, BashTool poll loop) contend
 * on the same .hatfield/messenger.sqlite file.
 *
 * Effects:
 * - WAL journal mode: allows concurrent readers + a single writer without
 *   immediate SQLITE_BUSY on reads. Readers never block readers; writers
 *   continue writing while readers see a consistent snapshot.
 * - busy_timeout=5000: waits up to 5 seconds for the write lock instead
 *   of immediately failing with SQLITE_BUSY. This prevents transient
 *   "database is locked" errors under the multi-process topology.
 * - synchronous=NORMAL: safe with WAL; avoids the fsync-at-every-write
 *   overhead of FULL while preserving crash safety for runtime state.
 *
 * Registered as a Doctrine middleware (tag: doctrine.middleware) via
 * autoconfiguration so it applies to all connections automatically.
 * Non-SQLite connections are safely skipped.
 */
final class SqlitePragmaMiddleware implements Middleware
{
    /**
     * @var list<string> PRAGMA statements applied to every new SQLite connection
     */
    public const PRAGMAS = [
        'PRAGMA journal_mode=WAL',
        'PRAGMA busy_timeout=5000',
        'PRAGMA synchronous=NORMAL',
    ];

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, self::PRAGMAS) implements Driver {
            /**
             * @param list<string> $pragmas
             */
            public function __construct(
                private readonly Driver $wrapped,
                private readonly array $pragmas,
            ) {
            }

            public function connect(array $params): Connection
            {
                $connection = $this->wrapped->connect($params);

                $this->applySqlitePragmas($connection);

                return $connection;
            }

            public function getDatabasePlatform(
                \Doctrine\DBAL\ServerVersionProvider $versionProvider,
            ): \Doctrine\DBAL\Platforms\AbstractPlatform {
                return $this->wrapped->getDatabasePlatform($versionProvider);
            }

            public function getExceptionConverter(): Driver\API\ExceptionConverter
            {
                return $this->wrapped->getExceptionConverter();
            }

            /**
             * Apply SQLite PRAGMAs if the underlying connection is SQLite.
             *
             * Uses the native PDO driver name to detect SQLite rather than
             * inspecting params, so non-SQLite connections are safely skipped.
             */
            private function applySqlitePragmas(Connection $connection): void
            {
                try {
                    $native = $connection->getNativeConnection();

                    if ($native instanceof \PDO && 'sqlite' === $native->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
                        foreach ($this->pragmas as $pragma) {
                            $connection->exec($pragma);
                        }
                    }
                } catch (\PDOException|\Exception) {
                    // Not a PDO-based SQLite connection — PRAGMAs would fail;
                    // silently skip.
                }
            }
        };
    }
}
