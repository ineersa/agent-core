<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

/**
 * TUI session lifecycle event kinds.
 *
 * Dispatched by InteractiveMode via {@see TuiSessionLifecycleDispatcher}
 * at session boundaries. Future slash-command handlers and extensions
 * subscribe to these events through {@see TuiRuntimeContext::$lifecycle}
 * to react to session transitions without coupling to the business logic
 * of the switch loop.
 */
enum TuiSessionLifecycleEventTypeEnum: string
{
    /** A fresh session started (with a real prompt). */
    case SessionStarted = 'session_started';

    /** An existing session was resumed. */
    case SessionResumed = 'session_resumed';

    /** A lazy draft session was activated (no DB row yet). */
    case SessionDraftStarted = 'session_draft_started';

    /** A session ended (quit or switch). */
    case SessionEnded = 'session_ended';
}
