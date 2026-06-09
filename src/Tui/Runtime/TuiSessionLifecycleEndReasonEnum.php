<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

/**
 * Why a TUI session lifecycle ended.
 *
 * Carried by {@see TuiSessionLifecycleEventDTO::$endReason} on
 * {@see TuiSessionLifecycleEventTypeEnum::SessionEnded} events so
 * subscribers and future extensions can distinguish a clean quit
 * from a session switch.
 */
enum TuiSessionLifecycleEndReasonEnum: string
{
    /** The user switched to another session (/resume or /new). */
    case Switch = 'switch';

    /** The user quit the application (Ctrl+C, SIGTERM, /quit). */
    case Quit = 'quit';
}
