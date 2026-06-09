<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

/**
 * Payload for a TUI session lifecycle event.
 *
 * Carries the event type, current session identity, draft/resume
 * status, and optional transition metadata so subscribers can
 * distinguish first startup from switch, resume from fresh, etc.
 *
 * This DTO intentionally omits raw prompt text, tool output,
 * session file content, and other sensitive data. Observability
 * via structured logging (see docs/datadog.md) is preferred for
 * detailed diagnostics.
 */
final readonly class TuiSessionLifecycleEventDTO
{
    /**
     * @param string|null $previousSessionId session ID of the session being
     *                                       left, when switching or quitting
     * @param string|null $endReason         reason the session ended:
     *                                       'switch' (switching to another session)
     *                                       or 'quit' (normal exit)
     */
    public function __construct(
        public TuiSessionLifecycleEventTypeEnum $type,
        public string $sessionId,
        public bool $isDraft,
        public bool $resuming,
        public ?string $previousSessionId = null,
        public ?string $endReason = null,
    ) {
    }
}
