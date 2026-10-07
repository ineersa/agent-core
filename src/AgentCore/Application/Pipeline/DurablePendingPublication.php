<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\ControlMessageOutboxInterface;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ControlOutboxDestination;
use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Ineersa\AgentCore\Domain\Message\RunControlTransitionMessageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One durable publication path for ledger invocations and small control outbox rows.
 * Broker sends happen only after outermost owner-lock release.
 */
final readonly class DurablePendingPublication
{
    public function __construct(
        private PreparedTransitionEventStoreInterface $transitions,
        private ExecutionOperationStoreInterface $operations,
        private ControlMessageOutboxInterface $outbox,
        private RunLockManager $locks,
        private MessageBusInterface $commandBus,
        private MessageBusInterface $executionBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Schedule publication after the outermost owner lock releases.
     * Prerequisites unfinished keep the cut capped and leave obligations intact.
     */
    public function scheduleAfterOwnerLock(string $runId): void
    {
        $this->locks->afterRelease($runId, function () use ($runId): void {
            $this->publishReady($runId);
        });
    }

    public function publishReady(string $runId): void
    {
        try {
            $this->transitions->assertTransitionReady($runId);
        } catch (\Throwable $exception) {
            $this->logFailure($runId, $exception);

            return;
        }

        foreach ($this->operations->pendingDeliveriesForRun($runId) as $envelope) {
            if (null === $envelope) {
                continue;
            }
            $message = $envelope->getMessage();
            \assert($message instanceof AbstractAgentBusMessage);
            try {
                $this->transitions->assertTransitionReady($runId);
                ($message instanceof DurableExecutionResult || $message instanceof ExecutionOutcomeUnknown ? $this->commandBus : $this->executionBus)->dispatch($envelope);
            } catch (\Throwable $exception) {
                $this->logFailure($runId, $exception);

                return;
            }
        }

        $this->publishControlOutbox($runId);
    }

    public function publishControlOutbox(string $runId): void
    {
        try {
            $this->transitions->assertTransitionReady($runId);
        } catch (\Throwable $exception) {
            $this->logFailure($runId, $exception);

            return;
        }

        foreach ($this->outbox->pendingForRun($runId) as $pending) {
            try {
                $this->transitions->assertTransitionReady($runId);
                $bus = ControlOutboxDestination::EXECUTION === $pending->destination ? $this->executionBus : $this->commandBus;
                $bus->dispatch($pending->payload);
                $this->outbox->acknowledge($runId, $pending->identity);
            } catch (\Throwable $exception) {
                $this->logFailure($runId, $exception);

                return;
            }
        }
    }

    /**
     * @param list<object> $controlActions
     */
    public function persistControlObligations(string $runId, array $controlActions): void
    {
        foreach ($controlActions as $action) {
            [$identity, $destination, $payload] = $this->controlObligation($action);
            $this->outbox->enqueue($runId, $identity, $destination, $payload);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: object}
     */
    private function controlObligation(object $action): array
    {
        if ($action instanceof DispatchCoordinationMessageDTO) {
            return [
                $this->messageIdentity($action->message),
                ControlOutboxDestination::COMMAND,
                $action->message,
            ];
        }
        if ($action instanceof RunControlTransitionMessageInterface) {
            if (!$action instanceof AbstractAgentBusMessage) {
                throw new \RuntimeException('Control outbox requires a concrete run-control message.');
            }
            return [
                $this->messageIdentity($action),
                ControlOutboxDestination::COMMAND,
                $action,
            ];
        }
        if ($action instanceof Envelope) {
            $message = $action->getMessage();
            if ($message instanceof ExecutionRequest) {
                return [
                    'execution-request:'.$message->effectId,
                    ControlOutboxDestination::EXECUTION,
                    $action,
                ];
            }
            if ($message instanceof AbstractAgentBusMessage) {
                return [
                    $this->messageIdentity($message),
                    $message instanceof DurableExecutionResult || $message instanceof ExecutionOutcomeUnknown
                        ? ControlOutboxDestination::COMMAND
                        : ControlOutboxDestination::COMMAND,
                    $action,
                ];
            }
        }
        if ($action instanceof AbstractAgentBusMessage) {
            return [
                $this->messageIdentity($action),
                ControlOutboxDestination::COMMAND,
                $action,
            ];
        }

        return ['control:'.hash('sha256', serialize($action)), ControlOutboxDestination::COMMAND, $action];
    }

    private function messageIdentity(AbstractAgentBusMessage $message): string
    {
        return hash('sha256', serialize([
            $message::class,
            $message->runId(),
            $message->turnNo(),
            $message->stepId(),
            $message->attempt(),
            $message->idempotencyKey(),
        ]));
    }

    private function logFailure(string $runId, \Throwable $exception): void
    {
        $this->logger->warning('execution.pending_delivery_failed', [
            'component' => 'durable_pending_publication',
            'event_type' => 'execution.pending_delivery_failed',
            'run_id' => $runId,
            'exception_class' => $exception::class,
        ]);
    }
}
