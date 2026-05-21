<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

use Ineersa\CodingAgent\Config\LoggingConfig;

/**
 * Creates LogReader instances wired to the configured log directory.
 *
 * Autowired with a LoggingConfig that carries the resolved logDir
 * from Hatfield settings (defaults → home → project).
 *
 * This is the canonical entry point for services and commands that
 * need to read log files — anything outside the DI container should
 * shell out to bin/console commands rather than constructing readers
 * directly.
 */
final readonly class LogReaderFactory
{
    public function __construct(
        private LoggingConfig $loggingConfig,
    ) {
    }

    /**
     * Create a LogReader for the configured log directory.
     */
    public function create(): LogReader
    {
        return new LogReader(new LogParser(), $this->loggingConfig->logDir);
    }
}
