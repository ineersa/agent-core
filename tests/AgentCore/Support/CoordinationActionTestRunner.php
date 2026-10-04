<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Handler\CoordinationActionHandler;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Symfony\Component\Messenger\MessageBusInterface;

final class CoordinationActionTestRunner
{
    public static function bus(MessageBusInterface $target, ?CommandStoreInterface $store = null, ?ToolBatchCollector $collector = null, ?StepDispatcher $dispatcher = null): MessageBusInterface
    {
        $handler = new CoordinationActionHandler($target, $store ?? new InMemoryCommandStore(), $collector ?? new ToolBatchCollector(), $dispatcher ?? new StepDispatcher($target, $target), new TestToolExecutionAuthorization());

        return new \Symfony\Component\Messenger\MessageBus([
            new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware(new \Symfony\Component\Messenger\Handler\HandlersLocator([
                DispatchCoordinationMessageDTO::class => [$handler->dispatchMessage(...)],
                MarkCommandAppliedDTO::class => [$handler->markCommandApplied(...)],
                RegisterToolBatchDTO::class => [$handler->registerToolBatch(...)],
            ])),
        ]);
    }

    public static function run(object $action, ?MessageBusInterface $bus = null, ?ToolBatchCollector $collector = null, ?StepDispatcher $dispatcher = null, ?CommandStoreInterface $store = null): void
    {
        $bus ??= new TestMessageBus();
        $handler = new CoordinationActionHandler($bus, $store ?? new InMemoryCommandStore(), $collector ?? new ToolBatchCollector(), $dispatcher ?? new StepDispatcher($bus, $bus), new TestToolExecutionAuthorization());
        match (true) {
            $action instanceof DispatchCoordinationMessageDTO => $handler->dispatchMessage($action),
            $action instanceof MarkCommandAppliedDTO => $handler->markCommandApplied($action),
            $action instanceof RegisterToolBatchDTO => $handler->registerToolBatch($action),
            default => throw new \LogicException('Unsupported test coordination action.'),
        };
    }
}
