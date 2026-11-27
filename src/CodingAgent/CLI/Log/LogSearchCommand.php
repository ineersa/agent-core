<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Log;

use Ineersa\CodingAgent\Logging\LogEntryRenderer;
use Ineersa\CodingAgent\Logging\LogFilter;
use Ineersa\CodingAgent\Logging\LogReader;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Search log entries across all log files.
 *
 * Usage:
 *   bin/console log:search "timeout"
 *   bin/console log:search "timeout" --level=WARNING
 *   bin/console log:search "timeout" --format=jsonl
 */
#[AsCommand(name: 'log:search', description: 'Search log entries across all log files')]
final class LogSearchCommand
{
    private const int MAX_RESULTS = 500;

    public function __construct(
        private readonly LogReader $reader,
        private readonly LogEntryRenderer $renderer,
    ) {
    }

    public function __invoke(
        OutputInterface $output,

        #[Argument(description: 'Search term (case-insensitive substring)')]
        string $query,

        #[Option(description: 'Filter by log level (e.g. ERROR, WARNING)')]
        ?string $level = null,

        #[Option(description: 'Start date/time (e.g. "-1 hour", "2026-05-01")')]
        ?string $from = null,

        #[Option(description: 'End date/time (e.g. "now", "2026-05-20")')]
        ?string $to = null,

        #[Option(description: 'Output format: table (default), jsonl, or toon')]
        string $format = 'table',
    ): int {
        $filter = new LogFilter(
            level: $level,
            search: $query,
            from: null !== $from ? new \DateTimeImmutable($from) : null,
            to: null !== $to ? new \DateTimeImmutable($to) : null,
            limit: self::MAX_RESULTS,
        );

        $this->renderer->render($this->reader->readFiles($this->reader->getLogFiles(), $filter), $format, $output);

        return Command::SUCCESS;
    }
}
