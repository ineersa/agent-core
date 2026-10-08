<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Symfony\Component\Messenger\MessageBusInterface;

final readonly class StepDispatcher
{
    public function __construct(
        private MessageBusInterface $commandBus,
    ) {
    }

    /** @param list<object> $actions */
    public function dispatchCoordinationActions(array $actions): void
    {
        foreach ($actions as $action) {
            // App domain coordinators enqueue durable transport messages under the
            // owner lock before cut publication; these are not volatile broker sends.
            $this->commandBus->dispatch($action);
        }
    }
}
