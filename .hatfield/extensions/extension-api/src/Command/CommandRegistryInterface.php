<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Command;

/**
 * Narrow provider interface consumed by the TUI adapter to register
 * extension-provided slash commands.
 *
 * Implemented by the host TUI adapter so commands registered by extensions
 * flow through to the host slash-command registry.
 *
 * This is the public contract; the concrete adapter is host-owned.
 */
interface CommandRegistryInterface
{
    /**
     * Register a command with its handler.
     *
     * @param CommandDefinitionDTO             $definition Command metadata
     * @param ExtensionCommandHandlerInterface $handler    The handler implementation
     */
    public function register(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void;
}
