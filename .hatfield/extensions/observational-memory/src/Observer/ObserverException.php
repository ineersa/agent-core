<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

/**
 * Extension-local durable Observer failure (not transient provider error).
 */
final class ObserverException extends \RuntimeException
{
    public const string CODE_CONFIG = 'observer_config_invalid';

    public const string CODE_EMPTY_RANGE = 'observer_empty_range';

    public function __construct(
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidContextWindow(?int $contextWindow): self
    {
        return new self(
            \sprintf('Observer model context_window is missing or nonpositive (%s).', null === $contextWindow ? 'null' : (string) $contextWindow),
        );
    }

    public static function emptyRange(string $runId, int $start, int $end): self
    {
        return new self(
            \sprintf('Observer received empty event range %d..%d for run %s.', $start, $end, $runId),
        );
    }
}
