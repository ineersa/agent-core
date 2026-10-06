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
    private bool $advanceRun = false;
    private string $cleanupRunCursor = '';
    private string $cleanupFileCursor = '';
    private bool $cleanupAdvanceRun = false;

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
            $this->advanceRun = false;
            $this->cleanupRunCursor = $this->cleanupFileCursor = '';
            $this->cleanupAdvanceRun = false;
            $this->publishNext();
            $this->reclaimNext();
        }
    }

    #[AsEventListener]
    public function onRunning(WorkerRunningEvent $event): void
    {
        if ($event->isWorkerIdle() && \in_array('run_control', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            $this->publishNext();
            $this->reclaimNext();
        }
    }

    private function publishNext(): void
    {
        if ('' === trim($this->sessionId) || 'unknown' === $this->sessionId) {
            return;
        }
        try {
            if ($this->advanceRun) {
                $this->runCursor = $this->nextRun($this->runCursor) ?? '';
                $this->fileCursor = '';
                $this->advanceRun = false;

                return;
            }
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
            [$filename, $envelope] = $snapshot;
            $turn = $envelope->turnNo;
            $step = $envelope->stepId;
            unset($snapshot, $envelope);
            $this->authorization->recoverRunning($this->runCursor, $turn, $step);
            $batch = $this->batches->load($this->runCursor, $turn, $step);
            if (null !== $batch) {
                foreach ($this->authorization->pendingDeliveries($this->runCursor, $turn, $step, $batch) as $message) {
                    ($message instanceof ToolCallResult || $message instanceof \Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown ? $this->commandBus : $this->executionBus)->dispatch($message);
                }
            }
            $this->fileCursor = $filename;
        } catch (\Throwable $exception) {
            // Failed runs remain discoverable on the next sweep, without
            // starving reserved children behind an unfinished parent transition.
            $this->advanceRun = true;
            $this->logger->warning('tool_execution.pending_delivery_failed', [
                'component' => 'tool_pending_delivery', 'event_type' => 'tool_execution.pending_delivery_failed',
                'session_id' => $this->sessionId, 'run_id' => $this->runCursor, 'exception_class' => $exception::class,
            ]);
        }
    }

    private function reclaimNext(): void
    {
        if ('' === trim($this->sessionId) || 'unknown' === $this->sessionId) {
            return;
        }
        try {
            if ($this->cleanupAdvanceRun) {
                $this->cleanupRunCursor = $this->nextRun($this->cleanupRunCursor) ?? '';
                $this->cleanupFileCursor = '';
                $this->cleanupAdvanceRun = false;

                return;
            }
            if ('' === $this->cleanupRunCursor) {
                $this->cleanupRunCursor = $this->nextRun('') ?? '';
                if ('' === $this->cleanupRunCursor) {
                    return;
                }
            }
            $this->transitions->assertTransitionReady($this->cleanupRunCursor);
            $next = $this->authorization->reclaimDisposedPayloads($this->cleanupRunCursor, $this->cleanupFileCursor);
            if ('' === $next) {
                $this->cleanupRunCursor = $this->nextRun($this->cleanupRunCursor) ?? '';
                $this->cleanupFileCursor = '';

                return;
            }
            $this->cleanupFileCursor = $next;
        } catch (\Throwable $exception) {
            $this->cleanupAdvanceRun = true;
            $this->logger->warning('tool_execution.payload_cleanup_failed', [
                'component' => 'tool_pending_delivery', 'event_type' => 'tool_execution.payload_cleanup_failed',
                'session_id' => $this->sessionId, 'run_id' => $this->cleanupRunCursor, 'exception_class' => $exception::class,
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
