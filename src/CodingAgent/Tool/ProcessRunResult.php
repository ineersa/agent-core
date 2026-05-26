<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Result of a single foreground process execution.
 *
 * @immutable
 */
final readonly class ProcessRunResult
{
    /**
     * @param positive-int|0 $durationMs Wall-clock duration in milliseconds
     */
    public function __construct(
        public string $stdout,
        public string $stderr,
        public ?int $exitCode,
        public bool $cancelled,
        public bool $timedOut,
        public ?string $outputPath,
        public int $durationMs,
    ) {
    }
}
