<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;

/**
 * Immutable outcome of a tool batch collection carrying acceptance flags, ordered results, and pending execution effects.
 */
final readonly class ToolBatchCollectOutcome
{
    /**
     * Initializes the outcome with acceptance, duplicate, and completion flags along with results and effects.
     *
     * @param list<ToolCallResult>  $orderedResults
     * @param list<ExecuteToolCall> $effectsToDispatch
     */
    public function __construct(
        public bool $accepted,
        public bool $duplicate,
        public bool $complete,
        public array $orderedResults = [],
        public array $effectsToDispatch = [],
    ) {
    }

    public static function rejected(): self
    {
        return new self(accepted: false, duplicate: false, complete: false);
    }

    public static function duplicate(): self
    {
        return new self(accepted: true, duplicate: true, complete: false);
    }

    /**
     * Creates a static instance for an accepted batch awaiting completion with optional effects.
     *
     * @param list<ExecuteToolCall> $effectsToDispatch
     */
    public static function acceptedPending(array $effectsToDispatch = []): self
    {
        return new self(accepted: true, duplicate: false, complete: false, effectsToDispatch: $effectsToDispatch);
    }

    /**
     * Creates a static instance for a fully completed batch with results and effects to dispatch.
     *
     * @param list<ToolCallResult>  $orderedResults
     * @param list<ExecuteToolCall> $effectsToDispatch
     */
    public static function acceptedComplete(array $orderedResults, array $effectsToDispatch = []): self
    {
        return new self(
            accepted: true,
            duplicate: false,
            complete: true,
            orderedResults: $orderedResults,
            effectsToDispatch: $effectsToDispatch,
        );
    }
}
