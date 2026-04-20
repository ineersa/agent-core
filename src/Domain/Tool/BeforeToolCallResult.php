<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Represents the outcome of a pre-execution validation check for a tool call within the agent domain. It encapsulates whether the call is permitted or blocked along with an optional reason for denial. This immutable value object facilitates clear decision-making before tool invocation.
 */
final readonly class BeforeToolCallResult
{
    public function __construct(
        public bool $block = false,
        public ?string $reason = null,
    ) {
    }

    public static function allow(): self
    {
        return new self();
    }

    public static function blocked(?string $reason = null): self
    {
        return new self(block: true, reason: $reason);
    }
}
