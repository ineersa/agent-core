<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Recurring maintenance for foreground supervision and abandoned managed work.
 *
 * A null backgroundedAt marks a row as private to the foreground BashTool
 * invocation. Finished rows stay available for one scheduler interval so that
 * BashTool can read its output, then this task removes only their exact
 * row-owned sidecars and database rows. Managed launch recovery also removes
 * stopped row-less scopes and completed accepted work whose actual controller
 * owner is absent. Unknown owners and live last-writer leases remain protected.
 */
#[AsPeriodicTask(frequency: self::INTERVAL_SECONDS, schedule: 'default')]
final class BackgroundProcessCleanupTask
{
    public const int INTERVAL_SECONDS = 300;

    public function __construct(
        private readonly BackgroundProcessManager $manager,
        private readonly ProcessStore $store,
        private readonly ProcessLifecycle $lifecycle,
        private readonly LoggerInterface $logger,
        private readonly RunOperationalProjectionRepository $projectionRepository,
        private readonly LockFactory $sessionOwnerLockFactory,
        private readonly string $runtimeCwd,
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

        $ownerLeases = [];
        try {
            $this->lifecycle->visitStoppedManagedArtifacts(function (string $logPath, string $statusPath, string $sessionId) use ($cutoff, &$cleaned, &$failed, &$ownerLeases): bool {
                // The producer and every inherited writer have stopped before this
                // callback. Row absence is safe only under this managed lease proof.
                $entity = $this->store->fetchByArtifactPaths($logPath, $statusPath);
                if (null === $entity) {
                    ++$cleaned;

                    return true;
                }
                if ($entity->sessionId !== $sessionId || null === $entity->finishedAt) {
                    return false;
                }
                if (null === $entity->backgroundedAt) {
                    if ($entity->finishedAt > $cutoff) {
                        return false;
                    }

                    $deleted = $this->store->deleteById($entity->id);
                    $deleted ? ++$cleaned : ++$failed;

                    return $deleted;
                }
                // A child run is owned by its parent controller, not a fictitious
                // child controller lock. Missing projection ownership is unknown.
                $ownerSessionId = $this->projectionRepository->findOwnerSessionId($sessionId);
                if (null === $ownerSessionId || '' === trim($ownerSessionId)) {
                    return false;
                }
                if (!isset($ownerLeases[$ownerSessionId])) {
                    $owner = $this->sessionOwnerLockFactory->createLock(
                        ControllerSessionOwnerLockStore::resourceForSession($this->runtimeCwd, $ownerSessionId),
                        ttl: null,
                    );
                    if (!$owner->acquire(blocking: false)) {
                        return false;
                    }
                    // Reuse the proof for this owner's other completed children.
                    // Retain exclusion through the visitor's directory removal.
                    $ownerLeases[$ownerSessionId] = $owner;
                }
                $deleted = $this->store->deleteById($entity->id);
                $deleted ? ++$cleaned : ++$failed;

                return $deleted;
            });
        } finally {
            foreach ($ownerLeases as $owner) {
                $owner->release();
            }
        }

        $this->logger->info('background_process.cleanup_completed', [
            'run_id' => null,
            'session_id' => null,
            'component' => 'tool.background_process',
            'event_type' => 'background_process.cleanup_completed',
            'cleaned_count' => $cleaned,
            'failed_count' => $failed,
        ]);
    }
}
