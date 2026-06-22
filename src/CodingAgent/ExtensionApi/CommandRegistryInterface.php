<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Narrow provider interface consumed by the TUI adapter to register
 * extension-provided slash commands.
 *
 * Implemented by the TUI-layer adapter and injected into
 * ExtensionToolRegistryBridge so commands registered by extensions
 * flow through to SlashCommandRegistry.
 *
 * This is the public contract — the concrete adapter lives in src/Tui/.
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
