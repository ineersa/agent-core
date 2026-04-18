<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class StepDispatcher
{
    public function __construct(
        private MessageBusInterface $executionBus,
        private MessageBusInterface $publisherBus,
    ) {
    }

    /**
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
