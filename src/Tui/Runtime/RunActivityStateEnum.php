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
     * Auto-compaction is in progress (async LLM summarization).
     *
     * This state is NOT reported as active — SubmitListener treats it
     * the same as Completed (routing user input as follow_up, not steer)
     * to avoid racing follow-up commands with in-flight compaction.
     * CancelListener special-cases Compacting to allow Escape/cancel.
     */
    case Compacting = 'compacting';

    /**
     * Whether the activity state represents an active run that should
     * accept steer (injected) messages instead of follow_up.
     *
     * Compacting is NOT active: user input during compaction must
     * NOT be routed as steer (which would race the async compaction).
     * SubmitListener sends follow_up for non-active states, and the
     * AutoCompactionHookSubscriber::containsUserCommandCommit guard
     * protects against command/compaction races.
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Starting, self::Running, self::WaitingHuman, self::Cancelling => true,
            self::Idle, self::Completed, self::Failed, self::Cancelled, self::Compacting => false,
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
