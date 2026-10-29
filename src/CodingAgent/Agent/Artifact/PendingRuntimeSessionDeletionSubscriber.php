<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Infrastructure\Doctrine\CommandRecord;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceipt;
use Ineersa\CodingAgent\Entity\BashLaunchOwnership;
use Ineersa\CodingAgent\Entity\BashRegistrationReceipt;
use Ineersa\CodingAgent\Entity\DeferredToolCompletion;
use Ineersa\CodingAgent\Entity\ToolBatchCall;
use Ineersa\CodingAgent\Entity\ToolBatchResult;
use Ineersa\CodingAgent\Entity\ToolBatchSchedule;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class PendingRuntimeSessionDeletionSubscriber
{
    public function __construct(
        private OwnedRunIdsProvider $ownership,
        private EntityManagerInterface $entityManager,
        private ToolBatchRunStoragePathsInterface $paths,
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
        // Nested child registries can live outside the numeric owner's tree.
        // Pass only captured paths to the existing post-transaction removal.
        $event->payloadDirectories = array_map(fn (string $runId): string => \dirname($this->paths->resolveToolBatchesDirectory($runId)).'/tool-payloads', $runs);
        $this->entityManager->createQueryBuilder()->delete(CommandRecord::class, 'c')
            ->where('c.runId IN (:runs)')->setParameter('runs', $runs)->getQuery()->execute();
        foreach ([ToolBatchResult::class, ToolBatchCall::class, DeferredToolCompletion::class] as $entity) {
            $this->entityManager->createQueryBuilder()->delete($entity, 'p')
                ->where('p.runId IN (:runs)')->setParameter('runs', $runs)->getQuery()->execute();
        }
        $this->entityManager->createQueryBuilder()->delete(ToolBatchSchedule::class, 'b')
            ->where('b.runId IN (:runs)')->setParameter('runs', $runs)->getQuery()->execute();
        $this->entityManager->createQueryBuilder()->delete(BackgroundCompletionReceipt::class, 'r')
            ->where('r.rootRunId IN (:runs)')->setParameter('runs', $runs)->getQuery()->execute();
        $this->entityManager->createQueryBuilder()->delete(BashRegistrationReceipt::class, 'r')
            ->where('IDENTITY(r.owner) = :owner')->setParameter('owner', (int) $event->sessionId)->getQuery()->execute();
        $this->entityManager->createQueryBuilder()->delete(BashLaunchOwnership::class, 'r')
            ->where('IDENTITY(r.owner) = :owner')->setParameter('owner', (int) $event->sessionId)->getQuery()->execute();
        // HatfieldSessionStore removes these paths and the owner tree only after
        // this metadata transaction succeeds. No filesystem deletion happens here.
    }
}
