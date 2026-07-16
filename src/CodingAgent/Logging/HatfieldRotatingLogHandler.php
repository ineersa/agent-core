<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

use Ineersa\CodingAgent\Config\LoggingConfig;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\LogRecord;

/**
 * Custom RotatingFileHandler for agent-core that writes JSONL logs
 * under .hatfield/logs/ with daily rotation and auto-directory creation.
 *
 * The log directory path, minimum level, and max rotation files are all
 * driven by {@see LoggingConfig} resolved from Hatfield settings, following
 * the same path-resolution pattern as theme paths and session storage.
 *
 * {@see LoggingConfig::$logDir} is always an absolute path (default:
 * {@see <CWD>/.hatfield/logs}) resolved by {@see SettingsResolver} from
 * the {@see logging.path} setting.
 */
final class HatfieldRotatingLogHandler extends RotatingFileHandler
{
    /**
     * @param LoggingConfig $loggingConfig Hatfield logging settings with
     *                                     the resolved log directory path,
     *                                     minimum level, and max file count
     */
    public function __construct(
        LoggingConfig $loggingConfig,
    ) {
        parent::__construct(
            filename: $loggingConfig->logDir.'/agent.log',
            maxFiles: $loggingConfig->maxFiles,
            level: $loggingConfig->level,
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
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException(\sprintf('Unable to create log directory "%s".', $dir));
            }
        }

        parent::write($record);
    }
}
