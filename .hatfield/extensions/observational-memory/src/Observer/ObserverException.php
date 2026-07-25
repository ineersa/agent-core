<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

/**
 * Extension-local durable Observer failure (not transient provider error).
 *
 * Carries a stable machine code so compaction/worker classification does not
 * depend on exception message wording.
 */
final class ObserverException extends \RuntimeException
{
    public const string CODE_INPUT_OVER_BUDGET = 'observer_input_over_budget';

    public const string CODE_TOOL_NOT_CALLED = 'observer_tool_not_called';

    public function __construct(
        public readonly string $failureCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function inputOverBudget(int $tokenEstimate, int $budget): self
    {
        return new self(
            self::CODE_INPUT_OVER_BUDGET,
            \sprintf('Observer input still exceeds budget after rendering (%d > %d).', $tokenEstimate, $budget),
        );
    }

    public static function toolNotCalled(): self
    {
        return new self(
            self::CODE_TOOL_NOT_CALLED,
            'Observer model did not call record_observations; coverage not advanced.',
        );
    }
}
