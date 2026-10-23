<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\CodingAgent\Agent\Artifact\OwnedRunIdsProvider;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionReasonEnum;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Controller ownership loss fences old hosts without deleting recoverable evidence. */
final readonly class CodeModeControllerSessionLifecycleListener
{
    public function __construct(private OwnedRunIdsProvider $ownership, private CodeModeExecutionRepository $executions, private RunLockManager $locks)
    {
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
                foreach ($this->executions->activeIdentities($runId) as $identity) {
                    $this->executions->interrupt($identity, $reason);
                }
            });
        }
    }
}
