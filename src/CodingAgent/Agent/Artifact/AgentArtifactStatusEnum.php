<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

/**
 * Lifecycle status for a parent-scoped agent artifact/child run.
 *
 * The status progresses: pending → running → completed|failed|cancelled.
 * needs_clarification is a terminal state for child runs that cannot
 * continue without human input — the parent session must decide what to
 * do with the blocked artifact.
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

    /** Child run stopped because it lacks context or needs human input. */
    case NeedsClarification = 'needs_clarification';
}
