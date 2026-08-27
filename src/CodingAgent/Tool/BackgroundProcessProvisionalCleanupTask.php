<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Recurring maintenance for private foreground Bash supervision state.
 *
 * A null backgroundedAt marks a row as private to the foreground BashTool
 * invocation. Finished rows stay available for one scheduler interval so that
 * BashTool can read its output, then this task removes only their exact
 * row-owned sidecars and database rows. Accepted background work is excluded.
 */
#[AsPeriodicTask(frequency: self::INTERVAL_SECONDS, schedule: 'default')]
final class BackgroundProcessProvisionalCleanupTask
{
    public const int INTERVAL_SECONDS = 300;

    public function __construct(
        private readonly BackgroundProcessManager $manager,
        private readonly ProcessStore $store,
        private readonly ProcessLifecycle $lifecycle,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $this->manager->refreshAllUnfinished();
        $cutoff = Clock::get()->now()->modify('-'.self::INTERVAL_SECONDS.' seconds');
        $cleaned = 0;
        $failed = 0;

        foreach ($this->store->fetchFinishedProvisionalBefore($cutoff) as $entity) {
            if (!$this->lifecycle->deleteExactRecordSidecars($entity->logPath, $entity->statusPath)) {
                ++$failed;
                continue;
            }

            if ($this->store->deleteById($entity->id)) {
                ++$cleaned;
            } else {
                ++$failed;
            }
        }

        $this->logger->info('background_process.provisional_cleanup_completed', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.provisional_cleanup_completed',
            'cleaned_count' => $cleaned,
            'failed_count' => $failed,
        ]);
    }
}
