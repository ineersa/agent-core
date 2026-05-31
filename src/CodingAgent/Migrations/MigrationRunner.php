<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\NoMigrationsFoundWithCriteria;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs pending Doctrine migrations on agent startup.
 *
 * Uses the Doctrine Migrations library directly (not the Symfony bundle)
 * with the ORM EntityManager's connection. The migration versions table
 * is stored alongside other tables in the shared messenger SQLite DB.
 *
 * Safe to call from multiple concurrent processes (controller + consumers):
 * the migrations library acquires a lock on the versions table during
 * execution. Only one process will execute migrations; others will skip
 * if all migrations are already applied.
 */
final class MigrationRunner
{
    private const string VERSIONS_TABLE = 'doctrine_migration_versions';

    /** @var non-empty-string Namespace for migration classes */
    private const string MIGRATION_NAMESPACE = 'DoctrineMigrations';

    private bool $ran = false;

    /**
     * @param non-empty-string $migrationDir Absolute path to migration classes
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $migrationDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Run pending migrations once.
     *
     * Subsequent calls are no-ops (idempotent per process lifetime).
     */
    public function __invoke(): void
    {
        if ($this->ran) {
            return;
        }

        $this->ran = true;

        try {
            $dependencyFactory = $this->createDependencyFactory();

            // Ensure the metadata storage table exists
            $dependencyFactory->getMetadataStorage()->ensureInitialized();

            // Resolve 'latest' alias to find target version
            $aliasResolver = $dependencyFactory->getVersionAliasResolver();
            try {
                $version = $aliasResolver->resolveVersionAlias('latest');
            } catch (NoMigrationsFoundWithCriteria) {
                // No migrations available at all — nothing to run
                $this->logger->debug('migration_runner.no_migrations', [
                    'component' => 'migration_runner',
                    'event_type' => 'migration_runner.no_migrations',
                ]);

                return;
            }

            // Calculate the plan up to the latest version
            $planCalculator = $dependencyFactory->getMigrationPlanCalculator();
            $plan = $planCalculator->getPlanUntilVersion($version);

            if (0 === \count($plan)) {
                $this->logger->debug('migration_runner.no_pending', [
                    'component' => 'migration_runner',
                    'event_type' => 'migration_runner.no_pending',
                ]);

                return;
            }

            // Execute the migration plan
            $migratorConfiguration = new MigratorConfiguration();
            $migratorConfiguration->setAllOrNothing(true);

            $migrator = $dependencyFactory->getMigrator();
            $migrator->migrate($plan, $migratorConfiguration);

            $this->logger->info('migration_runner.completed', [
                'component' => 'migration_runner',
                'event_type' => 'migration_runner.completed',
                'migrations_count' => \count($plan),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('migration_runner.failed', [
                'component' => 'migration_runner',
                'event_type' => 'migration_runner.failed',
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to run database migrations: '.$e->getMessage(), 0, $e);
        }
    }

    private function createDependencyFactory(): DependencyFactory
    {
        $connection = $this->entityManager->getConnection();

        $configuration = new Configuration();
        $configuration->addMigrationsDirectory(self::MIGRATION_NAMESPACE, $this->migrationDir);
        $configuration->setAllOrNothing(true);

        // Use the shared DB for the versions table
        $storageConfig = new TableMetadataStorageConfiguration();
        $storageConfig->setTableName(self::VERSIONS_TABLE);
        $configuration->setMetadataStorageConfiguration($storageConfig);

        return DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($connection),
        );
    }
}
