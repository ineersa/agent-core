<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\CodingAgent\Agent\Artifact\OwnedRunIdsProvider;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionReasonEnum;
use Ineersa\CodingAgent\Application\Deferred\DeferredControllerLossRecovery;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Controller ownership loss fences old hosts without deleting recoverable evidence. */
final readonly class CodeModeControllerSessionLifecycleListener
{
    public function __construct(
        private OwnedRunIdsProvider $ownership,
        private CodeModeExecutionRepository $executions,
        private RunLockManager $locks,
        private ToolBatchRunStoragePathsInterface $paths,
        private DeferredControllerLossRecovery $recovery,
    ) {
    }

    #[AsEventListener]
    public function starting(ControllerSessionStartingEvent $event): void
    {
        $this->interrupt($event->sessionId, CodeModeInterruptionReasonEnum::ControllerRestarted);
    }

    #[AsEventListener(priority: 512)]
    public function shutdown(ControllerSessionShutdownEvent $event): void
    {
        if (!$event->permanentDeletion) {
            $this->interrupt($event->sessionId, CodeModeInterruptionReasonEnum::ControllerShutdown);
        }
    }

    private function interrupt(string $owner, CodeModeInterruptionReasonEnum $reason): void
    {
        foreach ($this->ownership->forOwner($owner) as $runId) {
            $this->locks->synchronized($runId, function () use ($runId, $reason): void {
                $identities = $this->executions->identitiesForRun($runId);
                if ([] === $identities) {
                    return;
                }
                // Storage lock still guards publication races during recovery.
                // Obligation, parking, and retirement stay with shared deferred ownership.
                $this->paths->withStorageWriteLock(
                    $this->paths->resolveToolBatchesDirectory($runId),
                    fn () => $this->recovery->recover($identities, $reason),
                );
            });
        }
    }
}
