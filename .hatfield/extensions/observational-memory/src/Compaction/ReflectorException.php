<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

/**
 * Durable Reflector failure with stable failure_code for storage/TUI.
 */
final class ReflectorException extends \RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
