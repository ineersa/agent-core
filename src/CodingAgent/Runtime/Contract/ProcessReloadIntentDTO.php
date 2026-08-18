<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Typed process-reload intent produced by the TUI's /reload command.
 *
 * Carries the current persisted session ID so the outer bin/console
 * bootstrap loop can relaunch the same session ('' = fresh draft).
 */
final readonly class ProcessReloadIntentDTO
{
    public function __construct(
        public string $sessionId,
    ) {
    }
}
