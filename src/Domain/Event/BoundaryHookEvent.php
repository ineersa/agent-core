<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

final class BoundaryHookEvent
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $hookName,
        public array $context = [],
    ) {
    }
}
