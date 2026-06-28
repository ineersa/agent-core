<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * DBAL driver middleware that applies critical SQLite PRAGMAs on every new
 * connection to prevent multi-process contention on the shared SQLite store.
 *
 * WHAT THIS FIXES (#228)
 * ──────────────────────
 * Multiple processes (controller, Messenger consumers, BashTool poll loop)
 * contend on the same .hatfield/messenger.sqlite file.  Without WAL mode and
 * a nonzero busy_timeout, SQLite immediately fails with SQLITE_BUSY when a
 * read or write arrives during an active write transaction, causing
 * background-process records to appear to "vanish" (ORM queries return null)
 * and operation retries to fail with "database is locked."
 *
 * APPLIED PRAGMAs
 * ───────────────
 * - journal_mode=WAL: allows concurrent readers + a single writer without
 *   blocking.  Readers never block readers; writers continue writing while
 *   readers see a consistent snapshot.  This is the PRIMARY fix for #228.
 * - busy_timeout=5000: waits up to 5 seconds for the write lock instead of
 *   immediately failing with SQLITE_BUSY.  This prevents transient lock
 *   errors under multi-process write contention.
 *
 * FAILURE SEMANTICS
 * ─────────────────
 * If a critical PRAGMA (WAL or busy_timeout) fails on a SQLite connection,
 * the middleware throws RuntimeException with diagnostic context.  Agent
 * startup fails loudly because without these PRAGMAs the concurrency fix
 * is not in place.
 *
 * Non-SQLite connections are skipped silently.
 *
 * DI / SERVICE WIRING
 * ───────────────────
 * Autoconfigured as a Doctrine middleware (tag: doctrine.middleware) via
 * MiddlewareInterface detection.  The logger defaults to NullLogger to
 * avoid container compilation cycles (LoggerInterface injection into a
 * DBAL middleware can create an EntityManager → Connection → Middleware
 * → Logger → … → EntityManager cycle).  Call setLogger() from service
 * config when a real logger is available without cycles.
 */
final class SqlitePragmaMiddleware implements Middleware
{
    /**
     * Critical PRAGMAs applied to every new SQLite connection.
     *
     * These are the PRIMARY fixes for #228.  Failure to apply either one
     * throws a RuntimeException so the agent does not start without the
     * concurrency protection.
     *
     * synchronous=NORMAL is deliberately omitted — it is already the
     * default in WAL mode on modern SQLite, and SQLite rejects changing
     * it inside a transaction (which occurs under DAMA/DoctrineTestBundle
     * test isolation), making it unverifiable in the test suite and adding
     * confusion without benefit.
     *
     * @var list<string>
     */
    public const PRAGMAS = [
        'PRAGMA journal_mode=WAL',
        'PRAGMA busy_timeout=5000',
    ];

    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * Optionally replace the default NullLogger with a real PSR-3 logger.
     *
     * Call this from services.yaml when the DI container can resolve a
     * logger without introducing EntityManager → Connection → Middleware
     * → Logger → … → EntityManager compilation cycles:
     *
     *   Ineersa\CodingAgent\Doctrine\SqlitePragmaMiddleware:
     *       calls:
     *           - [setLogger, ['@logger']]
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function wrap(Driver $driver): Driver
    {
        return new SqlitePragmaMiddlewareDriver($driver, self::PRAGMAS, $this->logger);
    }
}

/**
 * @internal Internal driver wrapper that applies SQLite PRAGMAs on every new
 *           SQLite connection.  Extends AbstractDriverMiddleware to delegate
 *           platform, exception converter, and other calls to the wrapped
 *           driver without boilerplate.
 */
final class SqlitePragmaMiddlewareDriver extends AbstractDriverMiddleware
{
    /**
     * @param list<string> $pragmas PRAGMA statements to apply on connect
     */
    public function __construct(
        Driver $wrapped,
        private readonly array $pragmas,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($wrapped);
    }

    public function connect(array $params): Connection
    {
        $connection = parent::connect($params);

        $this->applyCriticalPragmas($connection);

        return $connection;
    }

    /**
     * Apply critical SQLite PRAGMAs, throwing on failure.
     *
     * Non-SQLite connections are skipped silently.  The detection uses the
     * native PDO driver name rather than inspecting $params, so wrapped
     * drivers and connection pooling are handled correctly.
     *
     * @throws \RuntimeException if a critical PRAGMA fails on a SQLite
     *                           connection.  The agent must not start without
     *                           WAL and busy_timeout.
     */
    private function applyCriticalPragmas(Connection $connection): void
    {
        $native = $connection->getNativeConnection();

        if (!($native instanceof \PDO) || 'sqlite' !== $native->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            return;
        }

        foreach ($this->pragmas as $pragma) {
            try {
                $connection->exec($pragma);
            } catch (\Throwable $e) {
                $pragmaName = 1 === preg_match('/^PRAGMA\s+(\w+)/', $pragma, $m) ? $m[1] : $pragma;

                $this->logger->error('doctrine.sqlite_pragma_failed', [
                    'component' => 'doctrine.sqlite_pragma',
                    'event_type' => 'doctrine.sqlite_pragma_failed',
                    'pragma' => $pragmaName,
                    'error' => $e::class,
                    'error_message' => $e->getMessage(),
                ]);

                throw new \RuntimeException(\sprintf('Critical SQLite PRAGMA "%s" failed: %s', $pragmaName, $e->getMessage()), 0, $e);
            }
        }
    }
}
