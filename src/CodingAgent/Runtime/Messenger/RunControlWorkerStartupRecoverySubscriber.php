<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * Explicit run_control worker startup/recovery after exclusive ownership.
 *
 * Ready shared projections are reused with zero archive reads. Missing or
 * withdrawn projections are reconstructed once through the existing rebuilder.
 * Ownership acquisition remains a higher-priority WorkerStarted listener.
 */
final readonly class RunControlWorkerStartupRecoverySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RunStateRebuilderInterface $runStateRebuilder,
        private RunOperationalProjectionRepository $projectionRepository,
        #[Autowire('%env(HATFIELD_SESSION_ID)%')]
        private string $sessionId,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Below ownership (1024) and above deferred-batch recovery (default 0).
        return [
            WorkerStartedEvent::class => ['onWorkerStarted', 512],
        ];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        if (!\in_array('run_control', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            return;
        }

        $sessionId = trim($this->sessionId);
        if ('' === $sessionId || 'unknown' === $sessionId) {
            return;
        }

        $this->recoverRun($sessionId);

        foreach ($this->projectionRepository->findBy(['ownerSessionId' => $sessionId]) as $projection) {
            $childRunId = $projection->runId;
            if ($childRunId === $sessionId) {
                continue;
            }

            $this->recoverRun($childRunId);
        }
    }

    private function recoverRun(string $runId): void
    {
        $this->logger->info('run_control.worker_startup_recovery', [
            'component' => 'RunControlWorkerStartupRecoverySubscriber',
            'event_type' => 'run_control.worker_startup_recovery',
            'session_id' => trim($this->sessionId),
            'run_id' => $runId,
        ]);

        $this->runStateRebuilder->rebuildIfStale(RunState::queued($runId), $runId);
    }
}
