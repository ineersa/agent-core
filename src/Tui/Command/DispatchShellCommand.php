<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Dispatch a shell command to the runtime for execution.
 *
 * Carries the exact submitted text (including the `!` prefix). The runtime
 * boundary keeps this raw value as the single command representation; the
 * pipeline derives executable text only for the bash tool effect.
 *
 * Output is NOT included in model context and must not trigger
 * an LLM turn.
 */
final readonly class DispatchShellCommand implements CommandResult
{
    public function __construct(
        public string $rawInput,
    ) {
    }
}
