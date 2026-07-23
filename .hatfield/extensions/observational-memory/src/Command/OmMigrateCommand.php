<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Command;

use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmSchemaMigrator;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmSqliteConnectionConfigurator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Apply extension-local OM schema migrations to the private SQLite database.
 */
#[AsCommand(name: 'om:migrate', description: 'Apply observational-memory schema migrations')]
final class OmMigrateCommand
{
    public function __construct(
        private readonly OmSchemaMigrator $migrator,
        private readonly OmSqliteConnectionConfigurator $connectionConfigurator,
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        $this->connectionConfigurator->configure();
        $this->migrator->migrate();
        $output->writeln('<info>OM schema migrations applied.</info>');

        return Command::SUCCESS;
    }
}
