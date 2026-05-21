<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Log;

use Ineersa\CodingAgent\Logging\LogFilter;
use Ineersa\CodingAgent\Logging\LogReaderFactory;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Search log entries across all log files.
 *
 * Usage:
 *   bin/console log:search "timeout"
 *   bin/console log:search "timeout" --level=WARNING
 *   bin/console log:search "timeout" --from="-1 hour" --to="now"
 */
#[AsCommand(name: 'log:search', description: 'Search log entries across all log files')]
final class LogSearchCommand
{
    private const int MAX_RESULTS = 500;

    public function __construct(
        private readonly LogReaderFactory $readerFactory,
    ) {
    }

    public function __invoke(
        #[Argument(description: 'Search term (case-insensitive substring)')]
        string $query,
        #[Option(description: 'Filter by log level (e.g. ERROR, WARNING)')]
        ?string $level = null,

        #[Option(description: 'Start date/time (e.g. "-1 hour", "2026-05-01")')]
        ?string $from = null,

        #[Option(description: 'End date/time (e.g. "now", "2026-05-20")')]
        ?string $to = null,

        ?OutputInterface $output = null,
    ): int {
        $io = new SymfonyStyle(new ArgvInput(), $output);

        $reader = $this->readerFactory->create();
        $fromDate = null !== $from ? new \DateTimeImmutable($from) : null;
        $toDate = null !== $to ? new \DateTimeImmutable($to) : null;
        $filter = new LogFilter(level: $level, search: $query, from: $fromDate, to: $toDate);

        $entries = [];
        $count = 0;
        foreach ($reader->readFiles($reader->getLogFiles(), $filter) as $entry) {
            $entries[] = $entry;
            ++$count;
            if ($count >= self::MAX_RESULTS) {
                break;
            }
        }

        if ([] === $entries) {
            $io->text('No matching log entries.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($entries as $logEntry) {
            $rows[] = [
                $logEntry->datetime->format('Y-m-d H:i:s'),
                $logEntry->level,
                mb_substr($logEntry->message, 0, 120),
            ];
        }

        $io->table(['Time', 'Level', 'Message'], $rows);

        return Command::SUCCESS;
    }
}
