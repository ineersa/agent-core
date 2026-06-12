<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Forward a raw payload string to the runtime for processing.
 *
 * Used by prompt-template slash commands and other TUI commands
 * that need runtime dispatch through {@see SubmitListener}.
 */
final readonly class DispatchRuntime implements CommandResult
{
    /**
     * @param string $payload The raw payload string to forward
     */
    public function __construct(
        public string $payload,
    ) {
    }
}
