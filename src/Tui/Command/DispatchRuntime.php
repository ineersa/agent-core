<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Forward a raw payload string to the runtime for processing.
 *
 * Intended for future use when commands need to interact with the
 * AgentCore runtime.
 */
final readonly class DispatchRuntime implements CommandResult
{
    /**
     * @param string $payload The raw payload string to forward
     */
    public function __construct(
        public string $payload,
    ) {}
}
