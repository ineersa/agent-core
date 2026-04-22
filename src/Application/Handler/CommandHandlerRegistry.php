<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Extension\CommandHandlerInterface;

/**
 * Named-lookup registry that resolves command handlers by their command kind.
 */
final class CommandHandlerRegistry
{
    /** @var iterable<CommandHandlerInterface> */
    private iterable $handlers;

    /**
     * Initializes the registry with a collection of command handlers.
     *
     * @param iterable<CommandHandlerInterface> $handlers
     */
    public function __construct(iterable $handlers)
    {
        $this->handlers = $handlers;
    }

    public function find(string $kind): ?CommandHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($kind)) {
                return $handler;
            }
        }

        return null;
    }
}
