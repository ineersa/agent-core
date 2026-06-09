<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Dispatch a shell command to the runtime for execution.
 *
 * Carries the parsed shell command text. The runtime/app layer
 * executes the command through the shared bash tool path and
 * projects the output into the transcript.
 *
 * Output is NOT included in model context and must not trigger
 * an LLM turn.
 */
final readonly class DispatchShellCommand implements CommandResult
{
    public function __construct(
        public string $command,
    ) {
    }
}
