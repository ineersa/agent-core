<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Migrations;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Runs the built-in Doctrine Migrations migrate command once on agent startup.
 *
 * Uses the Symfony Console Application service (console.messenger.application)
 * to invoke the standard doctrine:migrations:migrate command programmatically.
 * This avoids reinventing migration execution — no custom DependencyFactory,
 * no manual MigrationPlanCalculator, no bespoke runner logic.
 *
 * Safe for concurrent controller+consumer processes because the migration
 * command acquires a lock on the versions table during execution.
 * Only one process executes migrations; others skip when already applied.
 */
final class StartupDatabaseMigrator
{
    private bool $ran = false;

    public function __construct(
        private readonly Application $consoleApplication,
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
            $input = new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                '--no-interaction' => true,
                '--allow-no-migration' => true,
            ]);

            $output = new NullOutput();

            $exitCode = $this->consoleApplication->doRun($input, $output);

            if (0 !== $exitCode) {
                throw new \RuntimeException(\sprintf('Database migration command exited with code %d.', $exitCode));
            }

            $this->logger->info('migration_runner.completed', [
                'component' => 'migration_runner',
                'event_type' => 'migration_runner.completed',
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
}
