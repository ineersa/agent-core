<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

/**
 * Reads and filters JSONL log files from .hatfield/logs/.
 *
 * Provides:
 *  - getLogFiles(): lists log files sorted by mtime (newest first)
 *  - readFiles(): generator-based streaming read with optional filtering
 *  - tail(): reads the last N matching entries from the newest log file
 *
 * All methods return LogEntry objects parsed via LogParser.
 */
final readonly class LogReader
{
    public function __construct(
        private LogParser $parser,
        private string $logDir,
    ) {
    }

    /**
     * List all .log files in the log directory, sorted newest-first by mtime.
     *
     * @return list<string> Absolute file paths
     */
    public function getLogFiles(): array
    {
        if (!is_dir($this->logDir)) {
            return [];
        }

        $pattern = $this->logDir.'/*.log';
        $files = glob($pattern, \GLOB_NOSORT);
        $files = false !== $files ? $files : [];

        $withMtime = [];
        foreach ($files as $file) {
            $mtime = filemtime($file);
            $withMtime[] = ['path' => $file, 'mtime' => false !== $mtime ? $mtime : 0];
        }

        usort($withMtime, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return array_map(static fn (array $entry): string => $entry['path'], $withMtime);
    }

    /**
     * Read log entries from the given file paths, optionally filtered.
     *
     * Returns a Generator for memory-safe streaming. Filtering happens
     * during iteration; only matching entries are yielded.
     *
     * @param list<string>   $files  File paths to read
     * @param LogFilter|null $filter Optional filter criteria
     *
     * @return \Generator<LogEntry>
     */
    public function readFiles(array $files, ?LogFilter $filter = null): \Generator
    {
        $emitted = 0;

        foreach ($files as $file) {
            $handle = @fopen($file, 'rb');
            if (false === $handle) {
                continue;
            }

            try {
                $lineNumber = 0;
                while (false !== ($line = fgets($handle))) {
                    ++$lineNumber;
                    $entry = $this->parser->parse($line, $file, $lineNumber);
                    if (null === $entry) {
                        continue;
                    }

                    if (null !== $filter && !$filter->matches($entry)) {
                        continue;
                    }

                    yield $entry;
                    ++$emitted;

                    if (null !== $filter?->limit && $emitted >= $filter->limit) {
                        return; // Break out of the generator
                    }
                }
            } finally {
                fclose($handle);
            }
        }
    }

    /**
     * Return the last N matching entries from the newest log file, newest-first.
     *
     * Streams the whole newest file and keeps a rolling window of the last N
     * matches, so memory stays bounded by N regardless of file size. The
     * filter's limit is ignored here: it would stop reading at the oldest
     * matches at the top of the file.
     *
     * @param int            $lines  Maximum number of entries to return
     * @param LogFilter|null $filter Optional filter criteria
     *
     * @return list<LogEntry>
     */
    public function tail(int $lines = 50, ?LogFilter $filter = null): array
    {
        $files = $this->getLogFiles();
        if ([] === $files || $lines < 1) {
            return [];
        }

        $unlimitedFilter = null !== $filter
            ? new LogFilter(level: $filter->level, search: $filter->search, from: $filter->from, to: $filter->to)
            : null;

        $window = new \SplQueue();
        foreach ($this->readFiles([$files[0]], $unlimitedFilter) as $entry) {
            $window->enqueue($entry);
            if ($window->count() > $lines) {
                $window->dequeue();
            }
        }

        return array_reverse(iterator_to_array($window, false));
    }
}
