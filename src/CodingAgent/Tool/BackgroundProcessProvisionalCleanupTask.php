<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Recurring maintenance for private foreground Bash supervision state.
 *
 * A null backgroundedAt marks a row as private to the foreground BashTool
 * invocation. Finished rows stay available for one scheduler interval so that
 * BashTool can read its output, then this task removes only their exact
 * row-owned sidecars and database rows. Accepted background work is excluded.
 */
#[AsPeriodicTask(frequency: BackgroundProcessManager::PROVISIONAL_CLEANUP_INTERVAL_SECONDS, schedule: 'default')]
final class BackgroundProcessProvisionalCleanupTask
{
    public function __construct(private readonly BackgroundProcessManager $manager)
    {
    }

    public function __invoke(): void
    {
        $this->manager->cleanupFinishedProvisional();
    }
}
