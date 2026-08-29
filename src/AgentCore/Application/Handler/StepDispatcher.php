<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Message\RunControlTransitionMessageInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class StepDispatcher
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessageBusInterface $executionBus,
    ) {
    }

    /**
     * Dispatches state transitions to the run-control command bus and external
     * I/O effects to the execution bus.
     *
     * @param list<object> $effects
     */
    public function dispatchEffects(array $effects): void
    {
        foreach ($effects as $effect) {
            try {
                $this->busFor($effect)->dispatch($effect);
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to dispatch execution effect.', previous: $exception);
            }
        }
    }

    private function busFor(object $effect): MessageBusInterface
    {
        return $effect instanceof RunControlTransitionMessageInterface
            ? $this->commandBus
            : $this->executionBus;
    }
}
