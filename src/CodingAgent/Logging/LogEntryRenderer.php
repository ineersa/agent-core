<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

use HelgeSverre\Toon\Toon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders log entries for the log:* console commands.
 *
 * Formats: "jsonl" (one compact JSON object per line), "toon", and "table"
 * (the default for any other value).
 */
final readonly class LogEntryRenderer
{
    /**
     * @param iterable<LogEntry> $entries
     */
    public function render(iterable $entries, string $format, OutputInterface $output): void
    {
        $count = 0;
        $rows = [];
        foreach ($entries as $entry) {
            ++$count;

            if ('jsonl' === $format) {
                $output->writeln(json_encode(self::entryToArray($entry), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
            } elseif ('toon' === $format) {
                $output->writeln(Toon::encode(self::entryToArray($entry)), OutputInterface::OUTPUT_RAW);
            } else {
                $rows[] = [
                    $entry->datetime->format('Y-m-d H:i:s'),
                    $entry->level,
                    mb_substr($entry->message, 0, 120),
                ];
            }
        }

        // Machine formats stay parseable: no entries means no output.
        if ('jsonl' === $format || 'toon' === $format) {
            return;
        }

        $io = new SymfonyStyle(new ArrayInput([]), $output);

        if (0 === $count) {
            $io->text('No matching log entries.');
        } else {
            $io->table(['Time', 'Level', 'Message'], $rows);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function entryToArray(LogEntry $entry): array
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
