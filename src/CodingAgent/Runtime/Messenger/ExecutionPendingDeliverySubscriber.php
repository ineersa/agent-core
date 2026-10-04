<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

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

    public function __construct(
        private readonly DoctrineExecutionOperationStore $operations,
        private readonly PreparedTransitionEventStoreInterface $transitions,
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
            $this->publishPage();
        }
    }

    #[AsEventListener]
    public function onRunning(WorkerRunningEvent $event): void
    {
        if ($event->isWorkerIdle() && \in_array('run_control', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            $this->publishPage();
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
            $message = $envelope->getMessage();
            \assert($message instanceof AbstractAgentBusMessage);
            try {
                // Armed rows can exist while owner coordination is unfinished.
                // A sweep must not make those transitions externally executable.
                $this->transitions->assertTransitionReady($message->runId());
                ($message instanceof DurableExecutionResult ? $this->commandBus : $this->executionBus)->dispatch($envelope);
            } catch (\Throwable $exception) {
                // Keep the durable row. Later sweeps retry it with the same identity.
                $this->logFailure($message->runId(), $exception);
            }
            $this->cursor = $effectId;
        }
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
