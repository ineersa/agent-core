<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Custom RotatingFileHandler for agent-core that writes JSONL logs
 * under .hatfield/logs/ with daily rotation and auto-directory creation.
 *
 * PHAR-safe: log directory is resolved from projectDir injected by the DI
 * container, not from __DIR__ or hardcoded relative paths.
 */
final class HatfieldRotatingLogHandler extends RotatingFileHandler
{
    /**
     * @param string $logDir   Absolute path to the log directory (e.g. /project/.hatfield/logs)
     * @param int    $level    Minimum log level (default: INFO)
     * @param int    $maxFiles Maximum number of rotated files to keep (default: 14)
     */
    public function __construct(
        string $logDir,
        int|Level $level = Level::Info,
        int $maxFiles = 14,
    ) {
        parent::__construct(
            filename: $logDir.'/agent.log',
            maxFiles: $maxFiles,
            level: $level,
            bubble: true,
            filePermission: 0644,
            useLocking: false,
        );

        // Force compact JSONL: one JSON object per line, no array wrapping.
        // BATCH_MODE_NEWLINES means each record gets its own line (JSONL).
        $formatter = new JsonFormatter(
            batchMode: JsonFormatter::BATCH_MODE_NEWLINES,
            appendNewline: true,
            includeStacktraces: true,
        );
        $this->setFormatter($formatter);
    }

    /**
     * Ensure the log directory exists before writing.
     *
     * {@inheritDoc}
     */
    protected function write(LogRecord $record): void
    {
        $dir = \dirname($this->getUrl());
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException(\sprintf('Unable to create log directory "%s".', $dir));
            }
        }

        parent::write($record);
    }
}
