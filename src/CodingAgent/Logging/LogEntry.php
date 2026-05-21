<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

/**
 * Immutable value object representing a single parsed log entry.
 *
 * Maps directly to Monolog\JsonFormatter output fields.
 */
final readonly class LogEntry
{
    /**
     * @param array<array-key, mixed> $context
     * @param array<array-key, mixed> $extra
     */
    public function __construct(
        public \DateTimeImmutable $datetime,
        public string $channel,
        public string $level,
        public string $message,
        public array $context = [],
        public array $extra = [],
        public ?string $sourceFile = null,
        public ?int $lineNumber = null,
    ) {
    }
}
