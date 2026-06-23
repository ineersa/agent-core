<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

/**
 * Lifecycle status for a parent-scoped agent artifact/child run.
 *
 * The status progresses: pending → running → completed|failed|cancelled.
 * needs_clarification is reserved for future interactive child modes;
 * AGENT-05 v1 foreground subagents finalize WaitingHuman as failed instead.
 */
enum AgentArtifactStatusEnum: string
{
    /** Artifact directory created, not yet started. */
    case Pending = 'pending';

    /** Child run is actively executing. */
    case Running = 'running';

    /** Child run completed successfully. */
    case Completed = 'completed';

    /** Child run terminated with an unrecoverable error. */
    case Failed = 'failed';

    /** Child run cancelled by the parent. */
    case Cancelled = 'cancelled';

    /**
     * Reserved for future interactive child modes (not used by v1 foreground subagents).
     *
     * WaitingHuman in non-interactive child runs is finalized as Failed.
     */
    case NeedsClarification = 'needs_clarification';
}
