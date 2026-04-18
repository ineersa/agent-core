<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

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
