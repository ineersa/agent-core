<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

/**
 * Lifecycle status for a parent-scoped agent artifact/child run.
 *
 * The status progresses: pending → running → completed|failed|cancelled.
 * needs_clarification marks an interactive foreground child waiting for human input or approval.
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
     * Interactive foreground child is waiting for human input or approval.
     *
     * Explicit legacy non-interactive children (session.interactive=false) still finalize WaitingHuman as Failed.
     */
    case NeedsClarification = 'needs_clarification';
}
