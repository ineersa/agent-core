<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Messenger;

use Ineersa\CodingAgent\Entity\RunOperationalProjectionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Owns the disposable operational projection for one controller session.
 *
 * This listener is deliberately limited to the dedicated run_control worker:
 * that worker is the sole future writer of the projection. The process keeps
 * this flock for its entire lifetime, so a competing worker fails before it
 * can clear or process the same session's coordination rows. InProcess runtime
 * has no Messenger worker lifecycle, so it intentionally does not enter this
 * process-only fence or cleanup path.
 */
final class RunControlSessionOwnerWorkerLifecycleSubscriber
{
    private ?LockInterface $sessionOwnerLock = null;

    public function __construct(
        private readonly RunOperationalProjectionRepository $projectionRepository,
        #[Autowire(service: 'hatfield.controller.session_owner.lock_factory')]
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(HATFIELD_SESSION_ID)%')]
        private readonly string $sessionId,
    ) {
    }

    #[AsEventListener(event: WorkerStartedEvent::class, priority: 256)]
    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        if (!self::isRunControlWorker($event->getWorker()->getMetadata()->getTransportNames())) {
            return;
        }

        $sessionId = trim($this->sessionId);
        if ('' === $sessionId || 'unknown' === $sessionId) {
            throw new \RuntimeException('run_control worker requires a stable HATFIELD_SESSION_ID before processing messages.');
        }

        $lock = $this->lockFactory->createLock(self::lockResource($sessionId), ttl: null, autoRelease: true);
        if (!$lock->acquire(blocking: false)) {
            $this->logger->error('run_control session owner lock conflict', [
                'component' => 'run_control_session_owner',
                'event_type' => 'run_control.session_owner_lock_conflict',
                'session_id' => $sessionId,
            ]);

            throw new \RuntimeException('run_control session is already owned by another live worker.');
        }

        $this->sessionOwnerLock = $lock;

        try {
            $this->projectionRepository->deleteForOwnerSession($sessionId);
        } catch (\Throwable $exception) {
            $this->releaseSessionOwnerLock();

            throw $exception;
        }

        $this->logger->info('run_control session owner lock acquired and projection cleared', [
            'component' => 'run_control_session_owner',
            'event_type' => 'run_control.session_owner_projection_cleared',
            'session_id' => $sessionId,
        ]);
    }

    #[AsEventListener(event: WorkerStoppedEvent::class)]
    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        if (self::isRunControlWorker($event->getWorker()->getMetadata()->getTransportNames())) {
            $this->releaseSessionOwnerLock();
        }
    }

    /** @param list<string> $transportNames */
    private static function isRunControlWorker(array $transportNames): bool
    {
        return \in_array('run_control', $transportNames, true);
    }

    private static function lockResource(string $sessionId): string
    {
        return 'hatfield.run_control.session.'.hash('sha256', $sessionId);
    }

    private function releaseSessionOwnerLock(): void
    {
        $lock = $this->sessionOwnerLock;
        $this->sessionOwnerLock = null;

        if (null !== $lock && $lock->isAcquired()) {
            $lock->release();
        }
    }
}
