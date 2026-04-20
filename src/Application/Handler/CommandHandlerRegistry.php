<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Extension\CommandHandlerInterface;

/**
 * The CommandHandlerRegistry acts as a central lookup mechanism for resolving command handlers by their specific kind. It maintains a registry of handler instances to facilitate efficient dispatching within the application's command handling pipeline.
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
