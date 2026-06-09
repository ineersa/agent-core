<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\Tui\Completion\FileMentionIndexBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rebuild the file mention completion index from the project CWD.
 *
 * Intended to be called offline (via a periodic tick or manually),
 * never from the TUI input handler.  Uses Symfony Finder with
 * explicit excludes and a hard entry cap.
 *
 * Atomic: writes to a temp file first, then renames into place.
 * If the lock is held by another instance, exits successfully
 * with a diagnostic message.
 */
#[AsCommand(
    name: 'completion:file-index:refresh',
    description: 'Rebuild the file mention completion index for the current project.',
)]
final class CompletionFileIndexRefreshCommand extends Command
{
    public function __construct(
        private readonly AppConfig $appConfig,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = $this->appConfig->cwd;
        $indexPath = $cwd.'/.hatfield/cache/file-mentions/index.jsonl';

        $builder = new FileMentionIndexBuilder(
            cwd: $cwd,
            indexPath: $indexPath,
        );

        try {
            $count = $builder->build();
            $output->writeln("File mention index refreshed: {$count} entries written to {$indexPath}");

            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            // Lock held or build failure — not fatal; the index
            // will be refreshed on the next cycle.
            $output->writeln("File mention index refresh skipped: {$e->getMessage()}");

            return Command::SUCCESS;
        }
    }
}
