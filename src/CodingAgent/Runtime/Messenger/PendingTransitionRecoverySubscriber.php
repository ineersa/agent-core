<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/** Reconciles one owned unfinished canonical transition per startup or idle tick. */
final class PendingTransitionRecoverySubscriber
{
    private string $pendingRunCursor = '';

    public function __construct(
        private readonly PreparedTransitionEventStoreInterface $transitions,
        private readonly PendingTransitionRecovery $recovery,
        private readonly RunLockManager $locks,
        private readonly Connection $connection,
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
            $runId = $this->nextOwnedRun($this->pendingRunCursor);
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

    private function nextOwnedRun(string $after): ?string
    {
        $run = $this->connection->fetchOne(<<<'SQL'
            WITH RECURSIVE owned_runs(run_id) AS (
                SELECT :owner
                UNION
                SELECT child.child_run_id FROM deferred_subagent_child child
                JOIN deferred_subagent_batch batch ON batch.lifecycle_id = child.batch_lifecycle_id
                JOIN owned_runs parent ON parent.run_id = batch.parent_run_id
            )
            SELECT run_id FROM owned_runs WHERE run_id > :after ORDER BY run_id LIMIT 1
            SQL, ['owner' => $this->sessionId, 'after' => $after]);

        return false === $run ? null : (string) $run;
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
