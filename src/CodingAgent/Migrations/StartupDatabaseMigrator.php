<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Migrations;

use Ineersa\CodingAgent\Session\SessionCatalogRecoveryService;
use Psr\Log\LoggerInterface;

/**
 * Runs pending database schema migrations once on agent startup.
 *
 * Delegates to ApplicationMigrationExecutor which applies known migration
 * classes directly via DBAL without filesystem scanning or the Symfony
 * Doctrine Migrations console command.
 *
 * After schema migrations, reconciles orphan session directories into the
 * hatfield_session catalog so existing events.jsonl streams remain resumable
 * when state.sqlite rows were lost.
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
 * Session catalog recovery is independently idempotent (ON CONFLICT(id) DO NOTHING).
 */
final class StartupDatabaseMigrator
{
    private bool $ran = false;

    public function __construct(
        private readonly ApplicationMigrationExecutor $applicationMigrationExecutor,
        private readonly ApplicationMigrationExecutor $transportMigrationExecutor,
        private readonly SessionCatalogRecoveryService $sessionCatalogRecovery,
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
            ($this->applicationMigrationExecutor)();
            ($this->transportMigrationExecutor)();
            // Catalog recovery needs hatfield_session DDL from application migrations
            // and must finish before interactive create/resume/list paths run.
            ($this->sessionCatalogRecovery)();
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
