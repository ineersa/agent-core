<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Migrations;

use Psr\Log\LoggerInterface;

/**
 * Runs pending database schema migrations once on agent startup.
 *
 * Delegates to ApplicationMigrationExecutor which applies known migration
 * classes directly via DBAL without filesystem scanning or the Symfony
 * Doctrine Migrations console command.
 *
 * This approach avoids:
 *   - Extracting migration files from the PHAR to a writable directory
 *   - Running the Symfony console command recursively via proc_open
 *   - Relying on GlobResource/Finder which uses realpath() (broken inside
 *     phar:// stream wrappers)
 *
 * Safe for concurrent controller+consumer processes because the migration
 * executor records applied versions in the doctrine_migration_versions table.
 * Only one process executes migrations; others skip when already applied.
 */
final class StartupDatabaseMigrator
{
    private bool $ran = false;

    public function __construct(
        private readonly ApplicationMigrationExecutor $executor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Run pending migrations once per process lifetime.
     *
     * Subsequent calls are idempotent no-ops.
     */
    public function __invoke(): void
    {
        if ($this->ran) {
            return;
        }

        $this->ran = true;

        try {
            ($this->executor)();
        } catch (\Throwable $e) {
            $this->logger->error('migration_runner.failed', [
                'component' => 'migration_runner',
                'event_type' => 'migration_runner.failed',
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to run database migrations: '.$e->getMessage(), 0, $e);
        }
    }
}
