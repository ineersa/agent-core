<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Migrations;

use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Runs the built-in Doctrine Migrations migrate command once on agent startup.
 *
 * Uses the doctrine_migrations.migrate_command service directly (instead of
 * a full Console Application) to invoke the standard Doctrine Migrations
 * command programmatically. This avoids the recursive Application::doRun()
 * that previously blocked controller/TUI startup when stdout was a pipe.
 *
 * Safe for concurrent controller+consumer processes because the migration
 * command acquires a lock on the versions table during execution.
 * Only one process executes migrations; others skip when already applied.
 */
final class StartupDatabaseMigrator
{
    private bool $ran = false;

    public function __construct(
        private readonly MigrateCommand $migrateCommand,
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
                '--allow-no-migration' => true,
            ]);
            $input->setInteractive(false);

            $output = new NullOutput();

            $exitCode = $this->migrateCommand->run($input, $output);

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
