<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Ineersa\CodingAgent\Runtime\Controller\SessionScopedLockResource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Exclusive process-lifetime ownership of one session's run_control consumer.
 *
 * Controller and recovery share the same Flock resource. A live worker that
 * holds the lock prevents reclaim; process death auto-releases the flock so a
 * supervised replacement or reclaim can proceed. Recovery must keep an
 * independent exclusive lock held for the entire SQL update.
 */
final class RunControlWorkerOwnership
{
    private ?LockInterface $held = null;

    public function __construct(
        #[Autowire(service: 'hatfield.controller.session_owner.lock_factory')]
        private readonly LockFactory $lockFactory,
        #[Autowire('%app.cwd%')]
        private readonly string $runtimeCwd,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Non-blocking acquire for the current process. TTL null keeps ownership
     * until release or process death (flock auto-release).
     *
     * @return array{acquired: bool, failure: ?string}
     */
    public function tryAcquire(string $sessionId): array
    {
        $sessionId = trim($sessionId);
        if ('' === $sessionId || 'unknown' === $sessionId) {
            return ['acquired' => false, 'failure' => 'missing_session_id'];
        }

        if (null !== $this->held && $this->held->isAcquired()) {
            return ['acquired' => true, 'failure' => null];
        }

        $lock = $this->createLock($sessionId);

        try {
            if (!$lock->acquire(blocking: false)) {
                $this->logger->error('run_control.worker_ownership_conflict', [
                    'component' => 'RunControlWorkerOwnership',
                    'event_type' => 'run_control.worker_ownership_conflict',
                    'session_id' => $sessionId,
                ]);

                return ['acquired' => false, 'failure' => 'ownership_held'];
            }
        } catch (\Throwable $exception) {
            $this->logger->error('run_control.worker_ownership_acquire_failed', [
                'component' => 'RunControlWorkerOwnership',
                'event_type' => 'run_control.worker_ownership_acquire_failed',
                'session_id' => $sessionId,
                'exception' => $exception,
            ]);

            return ['acquired' => false, 'failure' => 'ownership_acquire_failed'];
        }

        $this->held = $lock;
        $this->logger->info('run_control.worker_ownership_acquired', [
            'component' => 'RunControlWorkerOwnership',
            'event_type' => 'run_control.worker_ownership_acquired',
            'session_id' => $sessionId,
        ]);

        return ['acquired' => true, 'failure' => null];
    }

    /**
     * Non-blocking exclusive lock for reclaim. Caller must release via
     * {@see releaseRecoveryLock()} in a finally block after SQL completes.
     * Same-process worker ownership also blocks reclaim.
     *
     * @return array{lock: ?LockInterface, failure: ?string}
     */
    public function tryAcquireForRecovery(string $sessionId): array
    {
        $sessionId = trim($sessionId);
        if ('' === $sessionId || 'unknown' === $sessionId) {
            return ['lock' => null, 'failure' => 'missing_session_id'];
        }

        if (null !== $this->held && $this->held->isAcquired()) {
            return ['lock' => null, 'failure' => 'live_owner_present'];
        }

        $lock = $this->createLock($sessionId);

        try {
            if (!$lock->acquire(blocking: false)) {
                return ['lock' => null, 'failure' => 'live_owner_present'];
            }
        } catch (\Throwable $exception) {
            $this->logger->error('run_control.worker_ownership_recovery_acquire_failed', [
                'component' => 'RunControlWorkerOwnership',
                'event_type' => 'run_control.worker_ownership_recovery_acquire_failed',
                'session_id' => $sessionId,
                'exception' => $exception,
            ]);

            return ['lock' => null, 'failure' => 'ownership_acquire_failed'];
        }

        return ['lock' => $lock, 'failure' => null];
    }

    public function releaseRecoveryLock(?LockInterface $lock): void
    {
        if (null === $lock) {
            return;
        }

        if ($lock->isAcquired()) {
            $lock->release();
        }
    }

    public function release(): void
    {
        $lock = $this->held;
        $this->held = null;
        if (null === $lock) {
            return;
        }

        if ($lock->isAcquired()) {
            $lock->release();
        }
    }

    private function createLock(string $sessionId): LockInterface
    {
        return $this->lockFactory->createLock(
            SessionScopedLockResource::runControlWorker($this->runtimeCwd, $sessionId),
            ttl: null,
            autoRelease: true,
        );
    }
}
