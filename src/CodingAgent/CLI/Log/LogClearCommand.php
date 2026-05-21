<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Log;

use Ineersa\CodingAgent\Logging\LogReaderFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Remove old rotated log files.
 *
 * Usage:
 *   bin/console log:clear
 *   bin/console log:clear --older-than=7d
 */
#[AsCommand(name: 'log:clear', description: 'Remove old rotated log files')]
final class LogClearCommand
{
    public function __construct(
        private readonly LogReaderFactory $readerFactory,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Remove files older than this relative time (e.g. "7 days ago", "14d")')]
        string $olderThan = '7 days ago',

        ?OutputInterface $output = null,
    ): int {
        $io = new SymfonyStyle(new ArgvInput(), $output);

        $reader = $this->readerFactory->create();
        $files = $reader->getLogFiles();
        $cutoff = new \DateTimeImmutable($olderThan);
        $removed = 0;

        foreach ($files as $file) {
            $mtime = filemtime($file);
            if (false === $mtime) {
                continue;
            }

            $fileDate = (new \DateTimeImmutable())->setTimestamp($mtime);
            if ($fileDate >= $cutoff) {
                continue;
            }

            if (unlink($file)) {
                $io->writeln('Removed '.basename($file));
                ++$removed;
            }
        }

        if (0 === $removed) {
            $io->text('No old log files to remove.');
        } else {
            $io->text("Removed {$removed} old log file(s).");
        }

        return Command::SUCCESS;
    }
}
