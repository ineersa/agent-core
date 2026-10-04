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
     * @param list<object>                                                                   $effects
     * @param array<int, \Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp> $authorizations
     */
    public function dispatchEffects(array $effects, array $authorizations = []): void
    {
        foreach ($effects as $effect) {
            try {
                $stamp = $authorizations[spl_object_id($effect)] ?? null;
                $this->busFor($effect)->dispatch($effect, null === $stamp ? [] : [$stamp]);
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to dispatch execution effect.', previous: $exception);
            }
        }
    }

    /** @param list<object> $actions */
    public function dispatchCoordinationActions(array $actions): void
    {
        foreach ($actions as $action) {
            $this->commandBus->dispatch($action);
        }
    }

    private function busFor(object $effect): MessageBusInterface
    {
        return $effect instanceof RunControlTransitionMessageInterface
            ? $this->commandBus
            : $this->executionBus;
    }
}
