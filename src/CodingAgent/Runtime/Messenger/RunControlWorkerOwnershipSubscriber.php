<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Acquire exclusive run_control ownership before the worker receives claims.
 *
 * Failure stops the worker immediately so a second consumer cannot share the
 * session queue under the long Doctrine lease.
 */
final readonly class RunControlWorkerOwnershipSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RunControlWorkerOwnership $ownership,
        #[Autowire('%env(HATFIELD_SESSION_ID)%')]
        private string $sessionId,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Highest priority so exclusivity is established before any other
            // WorkerStarted listener (including deferred-batch recovery) can
            // enqueue work against a contested session queue.
            WorkerStartedEvent::class => ['onWorkerStarted', 1024],
            WorkerStoppedEvent::class => 'onWorkerStopped',
        ];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        if (!\in_array('run_control', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            return;
        }

        $result = $this->ownership->tryAcquire($this->sessionId);
        if ($result['acquired']) {
            return;
        }

        $failure = $result['failure'] ?? 'ownership_held';
        $this->logger->error('run_control.worker_start_rejected', [
            'component' => 'RunControlWorkerOwnershipSubscriber',
            'event_type' => 'run_control.worker_start_rejected',
            'session_id' => $this->sessionId,
            'failure' => $failure,
        ]);

        $event->getWorker()->stop();
        throw new \RuntimeException(\sprintf('run_control worker refused to start for session %s (%s); another live owner holds exclusive claim ownership.', trim($this->sessionId), $failure));
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        if (!\in_array('run_control', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            return;
        }

        $this->ownership->release();
    }
}
