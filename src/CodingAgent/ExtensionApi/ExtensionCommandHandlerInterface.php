<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Handler contract for an extension-registered slash command.
 *
 * The handler receives the command arguments and a UI-agnostic context
 * (CommandContextInterface) for surfacing messages to the user.
 *
 * @see CommandDefinitionDTO
 * @see CommandContextInterface
 */
interface ExtensionCommandHandlerInterface
{
    /**
     * Handle the slash command invocation.
     *
     * @param string                  $args    Everything after the command name, trimmed
     * @param CommandContextInterface $context UI-agnostic context for notifying the user
     */
    public function handle(string $args, CommandContextInterface $context): void;
}
