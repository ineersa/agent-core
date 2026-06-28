<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

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
 *   Applied best-effort: if the connection already holds an active
 *   transaction, SQLite rejects changing the safety level ("Safety level
 *   may not be changed inside a transaction") and the middleware logs
 *   the failure via error_log. The connection operates with the default
 *   synchronous setting in that case.
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
        return new SqlitePragmaMiddlewareDriver($driver, self::PRAGMAS);
    }
}

/**
 * @internal Internal driver wrapper that applies SQLite PRAGMAs on every new SQLite connection.
 *
 * Extends AbstractDriverMiddleware to delegate platform and exception converter
 * calls to the wrapped driver without boilerplate.
 */
final class SqlitePragmaMiddlewareDriver extends AbstractDriverMiddleware
{
    /**
     * @param list<string> $pragmas PRAGMA statements to apply on every new SQLite connection
     */
    public function __construct(
        Driver $wrapped,
        private readonly array $pragmas,
    ) {
        parent::__construct($wrapped);
    }

    public function connect(array $params): Connection
    {
        $connection = parent::connect($params);

        $this->applySqlitePragmas($connection);

        return $connection;
    }

    /**
     * Apply SQLite PRAGMAs if the underlying connection is SQLite.
     *
     * Uses the native PDO driver name to detect SQLite rather than
     * inspecting params, so non-SQLite connections are safely skipped.
     *
     * Each step is independently guarded: non-PDO connections skip
     * without exceptions, PDO detection failures log and return
     * gracefully, PRAGMA application failures log diagnostic context
     * and continue in degraded mode (operates without WAL/busy_timeout,
     * which may re-introduce multi-process contention on the shared
     * SQLite file).
     */
    private function applySqlitePragmas(Connection $connection): void
    {
        try {
            $native = $connection->getNativeConnection();

            if ($native instanceof \PDO && 'sqlite' === $native->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
                foreach ($this->pragmas as $pragma) {
                    try {
                        $connection->exec($pragma);
                    } catch (\Throwable $e) {
                        $pragmaName = 1 === preg_match('/^PRAGMA\s+(\w+)/', $pragma, $m) ? $m[1] : $pragma;
                        // PRAGMA application failure: intentional local degradation.
                        // The connection operates without this PRAGMA effect, which
                        // may re-introduce #228 contention symptoms but does not
                        // prevent normal operation. Log diagnostic context.
                        // @see docs/datadog.md for structured log field conventions.
                        error_log(\sprintf(
                            '[sqlite_pragma_failure] component=doctrine.sqlite_pragma pragma=%s error=%s error_message=%s',
                            $pragmaName,
                            $e::class,
                            $e->getMessage(),
                        ));
                    }
                }
            }
        } catch (\Throwable $e) {
            // Could not detect or apply PRAGMAs — the connection
            // will operate without WAL/busy_timeout, potentially
            // re-introducing the multi-process contention from #228.
            // This is an intentional local degradation with logging.
            error_log(\sprintf(
                '[sqlite_pragma_skip] component=doctrine.sqlite_pragma error=%s error_message=%s',
                $e::class,
                $e->getMessage(),
            ));
        }
    }
}
