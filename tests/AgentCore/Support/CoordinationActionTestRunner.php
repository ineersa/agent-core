<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Handler\CoordinationActionHandler;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Symfony\Component\Messenger\MessageBusInterface;

final class CoordinationActionTestRunner
{
    public static function bus(MessageBusInterface $target, ?CommandStoreInterface $store = null, ?ToolBatchCollector $collector = null, ?StepDispatcher $dispatcher = null): MessageBusInterface
    {
        $collector ??= new ToolBatchCollector();
        $handler = new CoordinationActionHandler($target, $store ?? new InMemoryCommandStore(), $collector, $dispatcher ?? new StepDispatcher($target, $target), new TestToolExecutionAuthorization());

        return new \Symfony\Component\Messenger\MessageBus([
            new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware(new \Symfony\Component\Messenger\Handler\HandlersLocator([
                DispatchCoordinationMessageDTO::class => [$handler->dispatchMessage(...)],
                MarkCommandAppliedDTO::class => [$handler->markCommandApplied(...)],
                \Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO::class => [$handler->enqueueCommand(...)],
                \Ineersa\AgentCore\Domain\Coordination\RejectCommandDTO::class => [$handler->rejectCommand(...)],
                RegisterToolBatchDTO::class => [$handler->registerToolBatch(...)],
                FinalizeToolBatchDTO::class => [static fn (FinalizeToolBatchDTO $action) => TestToolBatchCoordination::finalize($collector, $action)],
            ])),
        ]);
    }

    public static function run(object $action, ?MessageBusInterface $bus = null, ?ToolBatchCollector $collector = null, ?StepDispatcher $dispatcher = null, ?CommandStoreInterface $store = null): void
    {
        $bus ??= new TestMessageBus();
        $handler = new CoordinationActionHandler($bus, $store ?? new InMemoryCommandStore(), $collector ?? new ToolBatchCollector(), $dispatcher ?? new StepDispatcher($bus, $bus), new TestToolExecutionAuthorization());
        match (true) {
            $action instanceof FinalizeToolBatchDTO => TestToolBatchCoordination::finalize($collector ?? throw new \LogicException('Batch coordination needs its original collector.'), $action),
            $action instanceof DispatchCoordinationMessageDTO => $handler->dispatchMessage($action),
            $action instanceof MarkCommandAppliedDTO => $handler->markCommandApplied($action),
            $action instanceof \Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO => $handler->enqueueCommand($action),
            $action instanceof \Ineersa\AgentCore\Domain\Coordination\RejectCommandDTO => $handler->rejectCommand($action),
            $action instanceof RegisterToolBatchDTO => $handler->registerToolBatch($action),
            default => throw new \LogicException('Unsupported test coordination action.'),
        };
    }
}
