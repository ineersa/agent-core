<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

/**
 * Authoritative TUI-level activity state for the current agent run.
 *
 * This replaces the previous heuristic of checking
 * $screen->registry()->getWorkingMessage() !== '' to decide whether
 * user messages should be routed as follow_up (idle) or steer (active).
 *
 * Transitions are maintained by SubmitListener (on send/start/cancel) and
 * by RuntimeEventPoller::updateActivity() on each poll cycle based on
 * observed RuntimeEventTypeEnum values.
 */
enum RunActivityStateEnum: string
{
    /** No active run or run completed without further activity. */
    case Idle = 'idle';

    /** Run just started (or follow_up just sent); first events not yet polled. */
    case Starting = 'starting';

    /** Agent is actively processing (turn/LLM/tool running). */
    case Running = 'running';

    /** Agent is blocked waiting for human input (HITL). */
    case WaitingHuman = 'waiting_human';

    /** Cancel was requested; waiting for graceful cancellation. */
    case Cancelling = 'cancelling';

    /** Run completed successfully. */
    case Completed = 'completed';

    /** Run ended with an error. */
    case Failed = 'failed';

    /** Run was cancelled. */
    case Cancelled = 'cancelled';

    /**
     * Whether the activity state represents an active run that should
     * accept steer (injected) messages instead of follow_up.
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Starting, self::Running, self::WaitingHuman, self::Cancelling => true,
            self::Idle, self::Completed, self::Failed, self::Cancelled => false,
        };
    }

    /**
     * Whether the activity state represents a terminal/completed run.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }
}
