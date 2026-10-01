<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Event;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Entity\RunOperationalState;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Prepares controller-owned session attach once the owner lock is held.
 *
 * Ready shared RunState/history projections published by cold TUI startup must
 * remain available for later controller attach and worker consumers. This listener
 * clears stale operational projection rows, restores root and previously owned
 * child operational identity from shared projections (or one cold recovery each),
 * then leaves lower-priority session-start hooks able to read initialized policy.
 */
final readonly class RunOperationalProjectionControllerSessionLifecycleListener
{
    public function __construct(
        private RunOperationalProjectionRepository $projectionRepository,
        private RunStateRebuilderInterface $runStateRebuilder,
        private ActiveRunContextInterface $activeRunContext,
    ) {
    }

    #[AsEventListener(event: ControllerSessionStartingEvent::class, priority: 256)]
    public function onSessionStarting(ControllerSessionStartingEvent $event): void
    {
        // HeadlessController holds the project/session owner lock while this
        // synchronous listener runs, before it launches any consumer.
        $sessionId = $event->sessionId;
        $ownedChildRunIds = [];
        foreach ($this->projectionRepository->findBy(['ownerSessionId' => $sessionId]) as $projection) {
            if (!$projection instanceof RunOperationalState) {
                continue;
            }
            if ($projection->runId === $sessionId) {
                continue;
            }
            $ownedChildRunIds[] = $projection->runId;
        }

        // Clear stale operational graphs, then republish authoritative identity.
        $this->projectionRepository->deleteForOwnerSession($sessionId);

        $this->recoverAndPublishOperationalIdentity($sessionId);
        foreach ($ownedChildRunIds as $childRunId) {
            $this->recoverAndPublishOperationalIdentity($childRunId);
        }
    }

    private function recoverAndPublishOperationalIdentity(string $runId): void
    {
        $result = $this->runStateRebuilder->rebuildIfStale(RunState::queued($runId), $runId);
        $state = $result->rebuiltState;
        if (null === $state) {
            // Ready shared projections matched the queued seed exactly (empty run)
            // or were already current; still republish operational identity.
            $state = $this->activeRunContext->stateFor($runId);
        }

        $this->activeRunContext->remember($state);
    }
}
