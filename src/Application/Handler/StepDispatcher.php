<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The StepDispatcher class orchestrates the execution of agent steps by routing effects and messages to distinct message buses. It decouples side-effect processing from publishing logic by delegating to separate MessageBusInterface instances.
 */
final readonly class StepDispatcher
{
    public function __construct(
        private MessageBusInterface $executionBus,
        private MessageBusInterface $publisherBus,
    ) {
    }

    /**
     * dispatches an array of effect messages to the execution bus.
     *
     * @param list<object> $effects
     */
    public function dispatchEffects(array $effects): void
    {
        foreach ($effects as $effect) {
            try {
                $this->executionBus->dispatch($effect);
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to dispatch execution effect.', previous: $exception);
            }
        }
    }

    public function publish(object $message): void
    {
        try {
            $this->publisherBus->dispatch($message);
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to dispatch publisher message.', previous: $exception);
        }
    }
}
