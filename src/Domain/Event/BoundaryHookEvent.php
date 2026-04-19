<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

/**
 * Defines a domain event representing the execution of a named boundary hook within the agent core. It encapsulates the hook identifier and an optional context array to provide metadata for downstream processing.
 */
final class BoundaryHookEvent
{
    /**
     * Initializes the event with a hook name and optional context array.
     *
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $hookName,
        public array $context = [],
    ) {
    }
}
