<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Log;

use HelgeSverre\Toon\Toon;
use Ineersa\CodingAgent\Logging\LogEntry;
use Ineersa\CodingAgent\Logging\LogFilter;
use Ineersa\CodingAgent\Logging\LogReaderFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

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

        #[Option(description: 'Output format: table (default), jsonl, or toon')]
        string $format = 'table',

        ?OutputInterface $output = null,
    ): int {
        $reader = $this->readerFactory->create();
        $filter = new LogFilter(level: $level, search: $search, limit: $lines);
        $entries = $reader->tail($lines, $filter);

        if ([] === $entries) {
            $io = new SymfonyStyle(new ArgvInput(), $output);
            $io->text('No matching log entries.');

            return Command::SUCCESS;
        }

        if ('toon' === $format) {
            $rawOutput = new StreamOutput(\STDOUT);
            foreach ($entries as $entry) {
                $rawOutput->write(Toon::encode(self::entryToJson($entry))."\n");
            }

            return Command::SUCCESS;
        }

        if ('jsonl' === $format) {
            $rawOutput = new StreamOutput(\STDOUT);
            foreach ($entries as $entry) {
                $rawOutput->write(json_encode(self::entryToJson($entry), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
            }

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle(new ArgvInput(), $output);
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

    /**
     * @return array<string, mixed>
     */
    private static function entryToJson(LogEntry $entry): array
    {
        return [
            'datetime' => $entry->datetime->format('Y-m-d H:i:s'),
            'channel' => $entry->channel,
            'level' => $entry->level,
            'message' => $entry->message,
            'context' => $entry->context,
            'extra' => $entry->extra,
            'sourceFile' => $entry->sourceFile,
            'lineNumber' => $entry->lineNumber,
        ];
    }
}
