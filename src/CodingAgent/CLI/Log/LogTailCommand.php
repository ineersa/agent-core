<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Log;

use Ineersa\CodingAgent\Logging\LogFilter;
use Ineersa\CodingAgent\Logging\LogReaderFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Show recent log entries (tail).
 *
 * Usage:
 *   bin/console log:tail
 *   bin/console log:tail --level=ERROR
 *   bin/console log:tail --lines=50
 *   bin/console log:tail --search="timeout"
 */
#[AsCommand(name: 'log:tail', description: 'Show recent log entries')]
final class LogTailCommand
{
    public function __construct(
        private readonly LogReaderFactory $readerFactory,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Filter by log level (e.g. ERROR, WARNING)')]
        ?string $level = null,

        #[Option(description: 'Maximum number of entries to show')]
        int $lines = 50,

        #[Option(description: 'Case-insensitive search term')]
        ?string $search = null,

        ?OutputInterface $output = null,
    ): int {
        $io = new SymfonyStyle(new ArgvInput(), $output);

        $reader = $this->readerFactory->create();
        $filter = new LogFilter(level: $level, search: $search, limit: $lines);
        $entries = $reader->tail($lines, $filter);

        if ([] === $entries) {
            $io->text('No matching log entries.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                $entry->datetime->format('Y-m-d H:i:s'),
                $entry->level,
                mb_substr($entry->message, 0, 120),
            ];
        }

        $io->table(['Time', 'Level', 'Message'], $rows);

        return Command::SUCCESS;
    }
}
