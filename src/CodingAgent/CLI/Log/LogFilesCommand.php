<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Log;

use HelgeSverre\Toon\Toon;
use Ineersa\CodingAgent\Logging\LogReaderFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * List log files with size and modification date.
 *
 * Usage:
 *   bin/console log:files
 *   bin/console log:files --format=jsonl
 */
#[AsCommand(name: 'log:files', description: 'List log files with size and modification date')]
final class LogFilesCommand
{
    public function __construct(
        private readonly LogReaderFactory $readerFactory,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Output format: table (default), jsonl, or toon')]
        string $format = 'table',

        ?OutputInterface $output = null,
    ): int {
        $reader = $this->readerFactory->create();
        $files = $reader->getLogFiles();

        if ([] === $files) {
            $io = new SymfonyStyle(new ArgvInput(), $output);
            $io->text('No log files found.');

            return Command::SUCCESS;
        }

        if ('toon' === $format) {
            $rawOutput = new StreamOutput(\STDOUT);
            foreach ($files as $file) {
                $size = filesize($file);
                $mtime = filemtime($file);
                $rawOutput->write(Toon::encode([
                    'file' => basename($file),
                    'size' => false !== $size ? self::formatBytes($size) : '?',
                    'sizeBytes' => false !== $size ? $size : null,
                    'modified' => false !== $mtime ? date('Y-m-d H:i:s', $mtime) : '?',
                    'path' => $file,
                ])."\n");
            }

            return Command::SUCCESS;
        }

        if ('jsonl' === $format) {
            $rawOutput = new StreamOutput(\STDOUT);
            foreach ($files as $file) {
                $size = filesize($file);
                $mtime = filemtime($file);
                $rawOutput->write(json_encode([
                    'file' => basename($file),
                    'size' => false !== $size ? self::formatBytes($size) : '?',
                    'sizeBytes' => false !== $size ? $size : null,
                    'modified' => false !== $mtime ? date('Y-m-d H:i:s', $mtime) : '?',
                    'path' => $file,
                ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
            }

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle(new ArgvInput(), $output);
        $rows = [];
        foreach ($files as $file) {
            $size = filesize($file);
            $mtime = filemtime($file);
            $rows[] = [
                basename($file),
                false !== $size ? self::formatBytes($size) : '?',
                false !== $mtime ? date('Y-m-d H:i:s', $mtime) : '?',
                $file,
            ];
        }

        $io->table(['File', 'Size', 'Modified', 'Path'], $rows);

        return Command::SUCCESS;
    }

    /**
     * Format byte count to human-readable string.
     */
    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;

        while ($bytes >= 1024 && $unit < \count($units) - 1) {
            $bytes = (int) ($bytes / 1024);
            ++$unit;
        }

        return $bytes.' '.$units[$unit];
    }
}
