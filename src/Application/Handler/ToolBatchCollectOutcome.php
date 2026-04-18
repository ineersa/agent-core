<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;

final readonly class ToolBatchCollectOutcome
{
    /**
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
     * @param list<ExecuteToolCall> $effectsToDispatch
     */
    public static function acceptedPending(array $effectsToDispatch = []): self
    {
        return new self(accepted: true, duplicate: false, complete: false, effectsToDispatch: $effectsToDispatch);
    }

    /**
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
