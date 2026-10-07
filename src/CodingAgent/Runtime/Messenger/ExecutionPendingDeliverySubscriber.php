<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\CodingAgent\Session\DoctrineExecutionOperationStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/** Republishes one bounded page per owner idle tick, without invoking external work. */
final class ExecutionPendingDeliverySubscriber
{
    private string $cursor = '';
    private string $cleanupCursor = '';
    private string $pendingRunCursor = '';

    public function __construct(
        private readonly DoctrineExecutionOperationStore $operations,
        private readonly PreparedTransitionEventStoreInterface $transitions,
        private readonly PendingTransitionRecovery $recovery,
        private readonly RunLockManager $locks,
        private readonly Connection $connection,
        #[Autowire(service: 'agent.command.bus')]
        private readonly MessageBusInterface $commandBus,
        #[Autowire(service: 'agent.execution.bus')]
        private readonly MessageBusInterface $executionBus,
        #[Autowire('%env(HATFIELD_SESSION_ID)%')]
        private readonly string $sessionId,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onStarted(WorkerStartedEvent $event): void
    {
        if (\in_array('run_control', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            $this->cursor = '';
            $this->cleanupCursor = '';
            $this->pendingRunCursor = '';
            $this->reconcilePendingTransition();
            $this->publishPage();
            $this->reclaimPage();
        }
    }

    #[AsEventListener]
    public function onRunning(WorkerRunningEvent $event): void
    {
        if ($event->isWorkerIdle() && \in_array('run_control', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            $this->reconcilePendingTransition();
            $this->publishPage();
            $this->reclaimPage();
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
            $this->logFailure($this->pendingRunCursor !== '' ? $this->pendingRunCursor : $this->sessionId, $exception);
        }
    }

    private function publishPage(): void
    {
        if ('' === trim($this->sessionId) || 'unknown' === $this->sessionId) {
            return;
        }
        try {
            $deliveries = $this->operations->pendingDeliveries($this->sessionId, $this->cursor);
        } catch (\Throwable $exception) {
            $this->logFailure($this->sessionId, $exception);

            return;
        }
        if ([] === $deliveries) {
            $this->cursor = '';

            return;
        }
        foreach ($deliveries as $effectId => $envelope) {
            $this->cursor = $effectId;
            if (null === $envelope) {
                continue;
            }
            $message = $envelope->getMessage();
            \assert($message instanceof AbstractAgentBusMessage);
            try {
                // Armed rows can exist while owner coordination is unfinished.
                // A sweep must not make those transitions externally executable.
                $this->transitions->assertTransitionReady($message->runId());
                ($message instanceof DurableExecutionResult || $message instanceof \Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown ? $this->commandBus : $this->executionBus)->dispatch($envelope);
            } catch (\Throwable $exception) {
                // Keep the durable row. Later sweeps retry it with the same identity.
                $this->logFailure($message->runId(), $exception);
            }
        }
    }

    private function reclaimPage(): void
    {
        if ('' === trim($this->sessionId) || 'unknown' === $this->sessionId) {
            return;
        }
        try {
            $next = $this->operations->reclaimDisposedPayloads($this->sessionId, $this->cleanupCursor);
        } catch (\Throwable $exception) {
            $this->logFailure($this->sessionId, $exception);

            return;
        }
        $this->cleanupCursor = $next;
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
        $this->logger->warning('execution.pending_delivery_failed', [
            'component' => 'execution_pending_delivery',
            'event_type' => 'execution.pending_delivery_failed',
            'run_id' => $runId,
            'session_id' => $this->sessionId,
            'exception_class' => $exception::class,
        ]);
    }
}
