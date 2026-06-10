<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * Typed DTO representing a session row for completion purposes.
 *
 * Carries only the fields needed by completion providers, keeping
 * the TuiCompletion layer independent of upstream session storage.
 */
final readonly class SessionCompletionRow
{
    public function __construct(
        public string $sessionId,
        public string $displayTitle,
    ) {
    }
}
