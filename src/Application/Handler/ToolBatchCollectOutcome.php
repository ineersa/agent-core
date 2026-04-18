<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Message\ToolCallResult;

final readonly class ToolBatchCollectOutcome
{
    /**
     * @param list<ToolCallResult> $orderedResults
     */
    public function __construct(
        public bool $accepted,
        public bool $duplicate,
        public bool $complete,
        public array $orderedResults = [],
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

    public static function acceptedPending(): self
    {
        return new self(accepted: true, duplicate: false, complete: false);
    }

    /**
     * @param list<ToolCallResult> $orderedResults
     */
    public static function acceptedComplete(array $orderedResults): self
    {
        return new self(accepted: true, duplicate: false, complete: true, orderedResults: $orderedResults);
    }
}
