<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RejectCommandDTO;
use Symfony\Component\Messenger\MessageBusInterface;

final class CoordinationActionTestRunner
{
    public static function run(
        object $action,
        ?MessageBusInterface $bus = null,
        ?ToolBatchCollector $collector = null,
        ?StepDispatcher $dispatcher = null,
        ?CommandStoreInterface $store = null,
        ?ToolBatchStoreInterface $batches = null,
    ): void {
        $bus ??= new TestMessageBus();
        $batches ??= new TestToolBatchStore();
        $commands = $store ?? new InMemoryCommandStore();
        match (true) {
            $action instanceof FinalizeToolBatchDTO => TestToolBatchCoordination::finalize($batches, $action),
            $action instanceof DispatchCoordinationMessageDTO => $bus->dispatch($action->message),
            $action instanceof MarkCommandAppliedDTO => $commands->markApplied($action->runId, $action->idempotencyKey),
            $action instanceof EnqueueCommandDTO => $commands->enqueue($action->command),
            $action instanceof RejectCommandDTO => $commands->markRejected($action->runId, $action->idempotencyKey, $action->reason),
            $action instanceof RegisterToolBatchDTO => (static function () use ($action, $batches, $dispatcher): void {
                TestToolBatchRegistration::apply($batches, $action);
                if (null !== $dispatcher) {
                    foreach ($action->effects as $call) {
                        if (isset($action->inFlight[$call->toolCallId])) {
                            $dispatcher->dispatchEffects([$call]);
                        }
                    }
                }
            })(),
            default => throw new \LogicException('Unsupported test coordination action.'),
        };
    }
}
