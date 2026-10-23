<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\CodingAgent\Agent\Artifact\OwnedRunIdsProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/** Reconciles one owned unfinished canonical transition per startup or idle tick. */
final class PendingTransitionRecoverySubscriber
{
    private string $pendingRunCursor = '';
    /** @var list<string> */
    private array $ownedRuns = [];
    private int $ownedRunIndex = 0;

    public function __construct(
        private readonly PreparedTransitionEventStoreInterface $transitions,
        private readonly PendingTransitionRecovery $recovery,
        private readonly RunLockManager $locks,
        private readonly OwnedRunIdsProvider $ownership,
        #[Autowire('%env(HATFIELD_SESSION_ID)%')]
        private readonly string $sessionId,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onStarted(WorkerStartedEvent $event): void
    {
        if (\in_array('run_control', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            $this->pendingRunCursor = '';
            $this->ownedRuns = [];
            $this->ownedRunIndex = 0;
            $this->reconcilePendingTransition();
        }
    }

    #[AsEventListener]
    public function onRunning(WorkerRunningEvent $event): void
    {
        if ($event->isWorkerIdle() && \in_array('run_control', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            $this->reconcilePendingTransition();
        }
    }

    private function reconcilePendingTransition(): void
    {
        if ('' === trim($this->sessionId) || 'unknown' === $this->sessionId) {
            return;
        }
        try {
            $runId = $this->nextOwnedRun();
            if (null === $runId) {
                $this->pendingRunCursor = '';

                return;
            }
            $this->pendingRunCursor = $runId;
            if (null === $this->transitions->verifiedPendingTransition($runId)) {
                return;
            }
            // Acquire the existing run lock. Never steal a live owner's work.
            $this->locks->synchronized($runId, function () use ($runId): void {
                $this->recovery->recover($runId);
            });
        } catch (\Throwable $exception) {
            $this->logFailure('' !== $this->pendingRunCursor ? $this->pendingRunCursor : $this->sessionId, $exception);
        }
    }

    private function nextOwnedRun(): ?string
    {
        // Reuse one traversal until every snapshot candidate has had its idle
        // opportunity, including failed candidates. New children enter next sweep.
        if ([] === $this->ownedRuns) {
            $this->ownedRuns = $this->ownership->forOwner($this->sessionId);
        }
        $run = $this->ownedRuns[$this->ownedRunIndex++] ?? null;
        if (null === $run) {
            $this->ownedRuns = [];
            $this->ownedRunIndex = 0;
        }

        return $run;
    }

    private function logFailure(string $runId, \Throwable $exception): void
    {
        $this->logger->warning('runtime.pending_transition_recovery_failed', [
            'component' => 'pending_transition_recovery',
            'event_type' => 'runtime.pending_transition_recovery_failed',
            'run_id' => $runId,
            'session_id' => $this->sessionId,
            'exception_class' => $exception::class,
        ]);
    }
}
