<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Migrations;

use Doctrine\DBAL\Connection;
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
 *     - Instantiates the migration and extracts its planned SQL via
 *       the AbstractMigration::$plannedSql property (set by addSql()).
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
     *   3. Add its FQCN to the END of this list.
     *   4. The runtime executor will apply it on next PHAR boot.
     *
     * @var list<class-string<AbstractMigration>>
     */
    private const KNOWN_MIGRATIONS = [
        \DoctrineMigrations\Version20260601152619::class,
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

        $this->ensureVersionsTable();

        foreach (self::KNOWN_MIGRATIONS as $class) {
            $versionId = $this->resolveVersionId($class);

            if ($this->isApplied($versionId)) {
                continue;
            }

            $this->executeMigration($class, $versionId);
        }

        $this->logger->info('migration_runner.completed', [
            'component' => 'migration_runner',
            'event_type' => 'migration_runner.completed',
        ]);
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
            'SELECT 1 FROM doctrine_migration_versions WHERE version = ?',
            [$versionId],
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

        // Collect SQL via the migration's up() method.
        // AbstractMigration stores addSql() statements in private
        // $plannedSql property. We extract them via reflection.
        $migration->up($this->createEmptySchema());

        $plannedSql = $this->extractPlannedSql($migration);

        if ([] === $plannedSql) {
            $logger->warning('migration_runner.no_sql', [
                'component' => 'migration_runner',
                'event_type' => 'migration_runner.no_sql',
                'version' => $versionId,
            ]);

            return;
        }

        // Execute SQL in a transaction.
        $startTime = microtime(true);

        $this->connection->beginTransaction();

        try {
            foreach ($plannedSql as $sql) {
                $this->connection->executeStatement($sql);
            }

            $executionTime = (int) ((microtime(true) - $startTime) * 1000000);

            $this->connection->insert('doctrine_migration_versions', [
                'version' => $versionId,
                'executed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'execution_time' => $executionTime,
            ]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

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
     * Extract planned SQL statements from an AbstractMigration instance.
     *
     * AbstractMigration stores addSql() calls in a private
     * $plannedSql property. We use reflection to access it since
     * there is no public accessor for the planned SQL list.
     *
     * This is the same SQL that Doctrine Migrations' executor would
     * collect and execute, but without needing the full DependencyFactory
     * or filesystem discovery.
     *
     * @return list<string>
     */
    private function extractPlannedSql(AbstractMigration $migration): array
    {
        $ref = new \ReflectionProperty(AbstractMigration::class, 'plannedSql');
        $ref->setAccessible(true);

        /** @var list<array{sql: string, params: array<string, mixed>, types: array<string, mixed>}> $planned */
        $planned = $ref->getValue($migration);

        return array_map(
            static fn (array $entry): string => $entry['sql'],
            $planned,
        );
    }

    /**
     * Create an empty Schema object for the migration's up() method.
     *
     * Since our migrator executes only the addSql() statements (not
     * Schema object manipulation), we pass an empty Schema. This
     * prevents errors if the migration happens to call
     * $schema->createTable() etc. (though the current migration at
     * Version20260601152619 uses only addSql()).
     *
     * If a future migration uses Schema manipulation without addSql(),
     * this method must be updated to introspect+clone+diff the schema.
     * See the class docblock for the alternative Schema-based path.
     */
    private function createEmptySchema(): \Doctrine\DBAL\Schema\Schema
    {
        return new \Doctrine\DBAL\Schema\Schema();
    }
}
