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
 * `previousSessionId` is set on start/resume/draft-start events
 * that follow a session switch, so subscribers and future extensions
 * can track which session the user came from.  It is null for the
 * very first session in a process (no prior iteration).
 *
 * This DTO intentionally omits raw prompt text, tool output,
 * session file content, and other sensitive data.  Observability
 * via structured logging (see docs/datadog.md) is preferred for
 * detailed diagnostics.
 */
final readonly class TuiSessionLifecycleEventDTO
{
    /**
     * @param string|null                           $previousSessionId session ID of the session that
     *                                                                 ended just before this event, when
     *                                                                 this event is the start of the next
     *                                                                 iteration after a switch
     * @param TuiSessionLifecycleEndReasonEnum|null $endReason         why the session
     *                                                                 ended ('switch' or 'quit').
     *                                                                 Only meaningful on SessionEnded;
     *                                                                 null for start events.
     */
    public function __construct(
        public TuiSessionLifecycleEventTypeEnum $type,
        public string $sessionId,
        public bool $isDraft,
        public bool $resuming,
        public ?string $previousSessionId = null,
        public ?TuiSessionLifecycleEndReasonEnum $endReason = null,
    ) {
    }
}
