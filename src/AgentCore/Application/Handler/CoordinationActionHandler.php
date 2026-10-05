<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class CoordinationActionHandler
{
    public function __construct(private MessageBusInterface $commandBus, private CommandStoreInterface $commandStore, private ToolBatchCollector $toolBatchCollector, private StepDispatcher $stepDispatcher, private \Ineersa\AgentCore\Contract\Tool\ToolExecutionAuthorizationInterface $toolAuthorization)
    {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function dispatchMessage(DispatchCoordinationMessageDTO $action): void
    {
        try {
            $this->commandBus->dispatch($action->message);
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException($action->errorMessage, previous: $exception);
        }
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function markCommandApplied(MarkCommandAppliedDTO $action): void
    {
        $this->commandStore->markApplied($action->runId, $action->idempotencyKey);
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function enqueueCommand(\Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO $action): void
    {
        $this->commandStore->enqueue($action->command);
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function rejectCommand(\Ineersa\AgentCore\Domain\Coordination\RejectCommandDTO $action): void
    {
        $this->commandStore->markRejected($action->runId, $action->idempotencyKey, $action->reason);
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function registerToolBatch(RegisterToolBatchDTO $action): void
    {
        $effects = $this->toolBatchCollector->registerExpectedBatch($action->runId, $action->turnNo, $action->stepId, $action->effects, redriveInFlight: true);
        foreach ($effects as $effect) {
            $this->toolAuthorization->arm($effect);
        }
        if ([] !== $effects) {
            $this->stepDispatcher->dispatchEffects($effects);
        }
    }
}
