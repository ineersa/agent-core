<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\LoggerInterface;

/**
 * Executes known Doctrine migration classes at runtime without filesystem
 * discovery or the Doctrine Migrations console command.
 *
 * WHY THIS EXISTS
 * ───────────────
 * The PHAR-packaged application cannot use Doctrine MigrationsBundle's
 * built-in migrate command because:
 *   1. The command scans migrations/ via GlobResource/Finder which uses
 *      realpath() and filesystem iteration — neither works cleanly inside
 *      phar:// stream wrappers.
 *   2. Extracting migration files to /tmp at PHAR boot (the previous
 *      approach) adds fragility and a filesystem dependency for what
 *      should be a pure-runtime operation.
 *
 * This executor solves both problems by maintaining an explicit ordered
 * list of known migration classes. It:
 *   - Creates the doctrine_migration_versions tracking table if absent.
 *   - For each known migration class not yet recorded as applied:
 *     - Instantiates the migration, calls up(), and reads its planned
 *       SQL via the public AbstractMigration::getSql() method.
 *     - Executes the SQL statements in a transaction.
 *     - Records the version in doctrine_migration_versions so dev
 *       doctrine:migrations:status commands remain consistent.
 *
 * DESIGN
 * ──────
 * - Only migrations listed in $knownMigrations are ever auto-applied.
 *   New migration classes MUST be added to this list in order.
 * - The doctrine_migration_versions table is the shared version store
 *   between this runtime executor and dev doctrine:migrations commands.
 * - Only UP direction is supported at runtime. Down/rollback is a
 *   development-only concern via the CLI.
 * - Idempotent per process (the $ran guard prevents double execution
 *   if called multiple times in the same request).
 *
 * @see StartupDatabaseMigrator
 *      The DI-injectable facade that calls this executor on agent startup.
 */
final class ApplicationMigrationExecutor
{
    /**
     * Ordered list of fully-qualified migration class names that the
     * runtime should auto-apply on startup.
     *
     * Adding a new migration:
     *   1. Generate via doctrine:migrations:diff in the source checkout.
     *   2. Make sure the class exists under migrations/ and has the
     *      DoctrineMigrations namespace.
     *   3. Add its FQCN to the END of this list (order matters: later
     *      migrations may depend on earlier DDL in this list).
     *   4. The runtime executor will apply it on next agent boot.
     *
     * Messenger run_control workers run StartupDatabaseMigrator in the same
     * process before consuming messages; subscribers on WorkerStarted assume
     * tables from KNOWN_MIGRATIONS already exist — do not query new tables
     * without registering their migration here first.
     *
     * @var list<class-string<AbstractMigration>>
     */
    private const KNOWN_MIGRATIONS = [
        \DoctrineMigrations\Version20260601152619::class,
        \DoctrineMigrations\Version20260606140000::class,
        \DoctrineMigrations\Version20260607000000::class,
        \DoctrineMigrations\Version20260608162000::class,
        // Version20260617141000 (messenger_messages) omitted: queue table is created
        // on doctrine.dbal.messenger_transport_connection via MessengerTransportSchemaEnsurer.
        \DoctrineMigrations\Version20260617141001::class,
        \DoctrineMigrations\Version20260617141002::class,
        \DoctrineMigrations\Version20260628140000::class,
        \DoctrineMigrations\Version20260710120000::class,
        \DoctrineMigrations\Version20260713130000::class,
        \DoctrineMigrations\Version20260713140000::class,
    ];

    private bool $ran = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Apply all known migrations that have not yet been recorded as applied.
     *
     * Safe to call multiple times — only executes on the first call.
     * Throws on failure — intended to fail agent startup loudly so the
     * operator knows the DB is broken rather than silently degrading.
     */
    public function __invoke(): void
    {
        if ($this->ran) {
            return;
        }

        $this->ran = true;

        $this->applySqliteHardening();

        $this->ensureVersionsTable();

        foreach (self::KNOWN_MIGRATIONS as $class) {
            $versionId = $this->resolveVersionId($class);

            if ($this->isApplied($versionId)) {
                continue;
            }

            $this->executeMigration($class, $versionId);
        }
    }

    // ─── SQLite hardening (#228) ───────────────────────────────────────

    /**
     * Apply and verify critical SQLite concurrency settings before any
     * migration runs.
     *
     * For file-based SQLite databases, sets WAL journal mode and verifies
     * that the configured busy_timeout is applied.  In-memory SQLite
     * databases are skipped for WAL (WAL requires a file-system) but
     * still verify busy_timeout.
     *
     * This runs outside any migration transaction, so PRAGMA journal_mode
     * changes are accepted by SQLite.
     *
     * @throws \RuntimeException if a critical setting cannot be applied
     *                           or verified.  Agent startup fails loudly
     *                           because these protections are required for
     *                           multi-process concurrency (#228).
     */
    private function applySqliteHardening(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return; // Not SQLite — nothing to harden.
        }

        $params = $this->connection->getParams();
        $isMemory = true === ($params['memory'] ?? false);

        if (!$isMemory) {
            // WAL journal mode: enables concurrent readers + a single writer
            // without blocking.  Readers never block readers; writers
            // continue while readers see a consistent snapshot.
            // This is the PRIMARY fix for #228.
            $result = $this->connection->executeQuery('PRAGMA journal_mode=WAL')->fetchOne();
            $resultMode = \is_string($result) ? strtolower($result) : '';

            if ('wal' !== $resultMode) {
                throw new \RuntimeException(\sprintf('Failed to set SQLite journal_mode to WAL. Expected "wal", got "%s". WAL mode is required for multi-process concurrency (#228).', $resultMode));
            }
        }

        // Verify busy_timeout >= 5000ms (a safe minimum lock-wait for
        // multi-process write contention).  The project default of exactly
        // 5000ms is proven by SqliteConnectionConfigTest.  This guard allows
        // environments to choose longer timeouts while ensuring the minimum
        // is never below 5s — a value that has proven too low under load.
        $busyTimeout = (int) $this->connection->executeQuery('PRAGMA busy_timeout')->fetchOne();

        if ($busyTimeout < 5000) {
            throw new \RuntimeException(\sprintf('SQLite busy_timeout is %dms, expected >= 5000ms. Check doctrine.yaml options.2 (PDO::ATTR_TIMEOUT) is set to 5. This is required for multi-process concurrency (#228).', $busyTimeout));
        }
    }

    // ─── Schema ───────────────────────────────────────────────────────────

    /**
     * Create the doctrine_migration_versions metadata table if it does not
     * already exist. The table format matches DoctrineMigrationsBundle's
     * default storage schema exactly so dev CLI commands are compatible.
     */
    private function ensureVersionsTable(): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist(['doctrine_migration_versions'])) {
            return;
        }

        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS doctrine_migration_versions (
                version VARCHAR(191) NOT NULL PRIMARY KEY,
                executed_at DATETIME DEFAULT NULL,
                execution_time INTEGER DEFAULT NULL
            )'
        );

        $this->logger->info('migration_runner.versions_table_created', [
            'component' => 'migration_runner',
            'event_type' => 'migration_runner.versions_table_created',
        ]);
    }

    // ─── Version tracking ─────────────────────────────────────────────────

    /**
     * Check whether a migration version has already been applied, by
     * querying the doctrine_migration_versions metadata table.
     */
    private function isApplied(string $versionId): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT 1 FROM doctrine_migration_versions WHERE version = ? OR version = ?',
            [$versionId, 'DoctrineMigrations\\'.$versionId],
        );

        return false !== $row;
    }

    /**
     * Derive the Doctrine Migrations version identifier from a migration
     * class FQCN (e.g. DoctrineMigrations\Version20260601152619).
     *
     * This matches the convention Doctrine uses internally when generating
     * migration classes, so the same identifier is used by dev CLI commands.
     */
    private function resolveVersionId(string $class): string
    {
        // Extract the class basename (e.g. "Version20260601152619").
        $parts = explode('\\', $class);

        return end($parts);
    }

    // ─── Migration execution ──────────────────────────────────────────────

    /**
     * Execute a single migration class and record it as applied.
     *
     * Extracts the planned SQL from AbstractMigration::$plannedSql
     * (set by addSql() calls in the migration's up() method) and
     * executes it in a single transaction.
     */
    private function executeMigration(string $class, string $versionId): void
    {
        $logger = $this->logger;

        $logger->info('migration_runner.executing', [
            'component' => 'migration_runner',
            'event_type' => 'migration_runner.executing',
            'version' => $versionId,
        ]);

        /** @var AbstractMigration $migration */
        $migration = new $class($this->connection, $logger);

        // Collect SQL via the public AbstractMigration::getSql() after
        // calling up(). The migration populates its planned SQL list via
        // addSql() calls inside up(); getSql() is the public accessor.
        $migrationSchema = $this->createEmptySchema();
        $migration->up($migrationSchema);

        $plannedSql = $migration->getSql();

        if ([] === $plannedSql) {
            // No SQL produced. Check whether the migration attempted Schema
            // object manipulation (tables/sequences/namespaces) without addSql().
            // Such migrations are not supported by this PHAR-safe executor;
            // migrations must use addSql() to produce explicit SQL.
            if ($this->hasSchemaChanges($migrationSchema)) {
                throw new \RuntimeException(\sprintf('Migration %s used Schema object manipulation without addSql(). Schema-based migrations are not supported by the PHAR-safe startup executor. Use addSql() to produce explicit SQL statements.', $versionId));
            }

            // Truly no-op migration (no SQL, no schema mutation).
            // Record it as applied so it is not re-attempted on every boot.
            $this->connection->insert('doctrine_migration_versions', [
                'version' => $versionId,
                'executed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'execution_time' => 0,
            ]);

            $logger->info('migration_runner.no_op', [
                'component' => 'migration_runner',
                'event_type' => 'migration_runner.no_op',
                'version' => $versionId,
            ]);

            return;
        }

        $startTime = microtime(true);
        $transactionActive = false;

        try {
            $this->connection->beginTransaction();
            $transactionActive = true;

            foreach ($plannedSql as $query) {
                $this->connection->executeStatement($query->getStatement());
            }

            $executionTime = (int) ((microtime(true) - $startTime) * 1000);

            $this->connection->insert('doctrine_migration_versions', [
                'version' => $versionId,
                'executed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'execution_time' => $executionTime,
            ]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($transactionActive) {
                $this->connection->rollBack();
            }

            $logger->error('migration_runner.execution_failed', [
                'component' => 'migration_runner',
                'event_type' => 'migration_runner.execution_failed',
                'version' => $versionId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(\sprintf('Migration %s failed: %s', $versionId, $e->getMessage()), 0, $e);
        }

        $logger->info('migration_runner.migration_completed', [
            'component' => 'migration_runner',
            'event_type' => 'migration_runner.migration_completed',
            'version' => $versionId,
            'execution_time' => $executionTime,
        ]);
    }

    /**
     * Create an empty Schema object for the migration's up() method.
     *
     * We pass an empty Schema because this executor only supports
     * migrations that use addSql() to produce explicit SQL statements.
     * Schema-based migrations (createTable/ addSequence/ etc.) will be
     * detected and rejected with a clear error message.
     */
    private function createEmptySchema(): Schema
    {
        return new Schema();
    }

    /**
     * Detect whether a Schema was mutated during a migration's up() call.
     *
     * Checks for tables, sequences, and namespaces added to the Schema.
     * This detects migrations that use the Schema API without addSql(),
     * which we do not support.
     */
    private function hasSchemaChanges(Schema $schema): bool
    {
        return [] !== $schema->getTables()
            || [] !== $schema->getSequences()
            || [] !== $schema->getNamespaces();
    }
}
