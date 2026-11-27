<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Log;

use Ineersa\CodingAgent\Logging\LogEntryRenderer;
use Ineersa\CodingAgent\Logging\LogFilter;
use Ineersa\CodingAgent\Logging\LogReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Show recent log entries (tail).
 *
 * Usage:
 *   bin/console log:tail
 *   bin/console log:tail --level=ERROR
 *   bin/console log:tail --lines=50
 *   bin/console log:tail --format=jsonl
 */
#[AsCommand(name: 'log:tail', description: 'Show recent log entries')]
final class LogTailCommand
{
    public function __construct(
        private readonly LogReader $reader,
        private readonly LogEntryRenderer $renderer,
    ) {
    }

    public function __invoke(
        OutputInterface $output,

        #[Option(description: 'Filter by log level (e.g. ERROR, WARNING)')]
        ?string $level = null,

        #[Option(description: 'Maximum number of entries to show')]
        int $lines = 50,

        #[Option(description: 'Case-insensitive search term')]
        ?string $search = null,

        #[Option(description: 'Output format: table (default), jsonl, or toon')]
        string $format = 'table',
    ): int {
        $entries = $this->reader->tail($lines, new LogFilter(level: $level, search: $search));
        $this->renderer->render($entries, $format, $output);

        return Command::SUCCESS;
    }
}
