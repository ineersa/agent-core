<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Infrastructure\Doctrine\CommandRecord;
use Ineersa\CodingAgent\Entity\ToolBatchSchedule;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class PendingRuntimeSessionDeletionSubscriber
{
    public function __construct(
        private OwnedRunIdsProvider $ownership,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[AsEventListener(priority: 1024)]
    public function onShutdown(ControllerSessionShutdownEvent $event): void
    {
        if (!$event->permanentDeletion) {
            return;
        }
        // Permanent deletion dispatches inside the session metadata transaction.
        // Discover descendants before shutdown listeners dispose ownership evidence.
        $runs = $this->ownership->forOwner($event->sessionId);
        $this->entityManager->createQueryBuilder()->delete(CommandRecord::class, 'c')
            ->where('c.runId IN (:runs)')->setParameter('runs', $runs)->getQuery()->execute();
        $this->entityManager->createQueryBuilder()->delete(ToolBatchSchedule::class, 'b')
            ->where('b.runId IN (:runs)')->setParameter('runs', $runs)->getQuery()->execute();
    }
}
