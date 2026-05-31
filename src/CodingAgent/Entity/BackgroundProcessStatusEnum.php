<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

/**
 * Backed string enum for background process lifecycle status.
 *
 * Computed by BackgroundProcessManager::resolveEntityStatus() from
 * filesystem state and entity fields. Not stored as a DB column —
 * the entity's finishedAt, exitCode, and stoppedByUser fields already
 * encode the same information.
 */
enum BackgroundProcessStatusEnum: string
{
    case Running = 'running';
    case Finished = 'finished';
    case FinishedUnclean = 'finished (unclean)';
    case Stopped = 'stopped';

    /**
     * Build a display-friendly status string for results that include
     * a non-zero exit code (e.g. "finished (exit code 1)").
     */
    public static function finishedWithExitCode(int $exitCode): string
    {
        return 'finished (exit code '.$exitCode.')';
    }
}
