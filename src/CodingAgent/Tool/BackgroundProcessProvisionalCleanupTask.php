<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\CodingAgent\Agent\Artifact\OwnedRunIdsProvider;
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
 * PID liveness and sidecar cleanup are restricted to this controller's runs:
 * another controller can share the database without sharing its PID namespace.
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
        private readonly OwnedRunIdsProvider $ownership,
        private readonly string $sessionId,
    ) {
    }

    public function __invoke(): void
    {
        if ('' === trim($this->sessionId) || 'unknown' === $this->sessionId) {
            return;
        }

        $sessionIds = $this->ownership->forOwner($this->sessionId);
        $this->manager->refreshAllUnfinished($sessionIds);
        $cutoff = Clock::get()->now()->modify('-'.self::INTERVAL_SECONDS.' seconds');
        $cleaned = 0;
        $failed = 0;
        $ids = [];

        foreach ($this->store->fetchFinishedProvisionalBefore($cutoff, $sessionIds) as $entity) {
            if (!$this->lifecycle->deleteExactRecordSidecars($entity->logPath, $entity->statusPath)) {
                ++$failed;
                continue;
            }

            $ids[] = $entity->id;
        }

        $cleaned = $this->store->deleteByIds($ids);
        $failed += \count($ids) - $cleaned;

        $this->logger->info('background_process.provisional_cleanup_completed', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.provisional_cleanup_completed',
            'run_id' => $this->sessionId,
            'session_id' => $this->sessionId,
            'cleaned_count' => $cleaned,
            'failed_count' => $failed,
        ]);
    }
}
