<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Log;

use Ineersa\CodingAgent\Logging\LogReaderFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * List log files with size and modification date.
 *
 * Usage:
 *   bin/console log:files
 */
#[AsCommand(name: 'log:files', description: 'List log files with size and modification date')]
final class LogFilesCommand
{
    public function __construct(
        private readonly LogReaderFactory $readerFactory,
    ) {
    }

    public function __invoke(
        ?OutputInterface $output = null,
    ): int {
        $io = new SymfonyStyle(new ArgvInput(), $output);

        $reader = $this->readerFactory->create();
        $files = $reader->getLogFiles();

        if ([] === $files) {
            $io->text('No log files found.');

            return Command::SUCCESS;
        }

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
