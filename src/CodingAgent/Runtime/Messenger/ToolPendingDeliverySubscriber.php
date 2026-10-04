<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\ToolExecutionAuthorization;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\CodingAgent\Session\SessionToolBatchStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/** Visits one snapshot per owner callback, retaining no decoded batch between callbacks. */
final class ToolPendingDeliverySubscriber
{
    private string $runCursor = '';
    private string $fileCursor = '';

    public function __construct(
        private readonly Connection $connection,
        private readonly SessionToolBatchStore $batches,
        private readonly ToolExecutionAuthorization $authorization,
        private readonly PreparedTransitionEventStoreInterface $transitions,
        #[Autowire(service: 'agent.command.bus')] private readonly MessageBusInterface $commandBus,
        #[Autowire(service: 'agent.execution.bus')] private readonly MessageBusInterface $executionBus,
        #[Autowire('%env(HATFIELD_SESSION_ID)%')] private readonly string $sessionId,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onStarted(WorkerStartedEvent $event): void
    {
        if (\in_array('run_control', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            $this->runCursor = $this->fileCursor = '';
            $this->publishNext();
        }
    }

    #[AsEventListener]
    public function onRunning(WorkerRunningEvent $event): void
    {
        if ($event->isWorkerIdle() && \in_array('run_control', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            $this->publishNext();
        }
    }

    private function publishNext(): void
    {
        if ('' === trim($this->sessionId) || 'unknown' === $this->sessionId) {
            return;
        }
        try {
            if ('' === $this->runCursor) {
                $this->runCursor = $this->nextRun('') ?? '';
                if ('' === $this->runCursor) {
                    return;
                }
            }
            // Coordination must finish before rediscovery can publish Armed work.
            $this->transitions->assertTransitionReady($this->runCursor);
            $snapshot = $this->batches->nextSnapshot($this->runCursor, $this->fileCursor);
            if (null === $snapshot) {
                $this->runCursor = $this->nextRun($this->runCursor) ?? '';
                $this->fileCursor = '';

                return;
            }
            foreach ($this->authorization->pendingDeliveries($snapshot[1]->batchState) as $message) {
                ($message instanceof ToolCallResult ? $this->commandBus : $this->executionBus)->dispatch($message);
            }
            // Delivery failure retains the cursor so the same snapshot is retried.
            $this->fileCursor = $snapshot[0];
        } catch (\Throwable $exception) {
            $this->logger->warning('tool_execution.pending_delivery_failed', [
                'component' => 'tool_pending_delivery', 'event_type' => 'tool_execution.pending_delivery_failed',
                'session_id' => $this->sessionId, 'run_id' => $this->runCursor, 'exception_class' => $exception::class,
            ]);
        }
    }

    private function nextRun(string $after): ?string
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
}
