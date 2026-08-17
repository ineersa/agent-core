<?php

declare(strict_types=1);

namespace Ineersa\Tui\Extension;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\CommandRegistryInterface;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;

/**
 * TUI-layer adapter that bridges extension-registered slash commands
 * into the process-scoped {@see SlashCommandCatalog}.
 *
 * Implements the CommandRegistryInterface (public ExtensionApi contract)
 * and wraps each ExtensionCommandHandlerInterface into an
 * ExtensionSlashCommandHandler so commands registered by extensions
 * flow through the native TUI command infrastructure.
 *
 * Extension handler objects are process-owned, so they are registered
 * on the shared catalog as default handlers; every per-session
 * {@see \Ineersa\Tui\Command\SlashCommandRegistry} resolves them at
 * execute time, keeping dynamic registrations immediately visible.
 */
final readonly class TuiCommandRegistryAdapter implements CommandRegistryInterface
{
    public function __construct(
        private SlashCommandCatalog $commandCatalog,
    ) {
    }

    public function register(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
    {
        $metadata = new CommandMetadata(
            name: $definition->name,
            aliases: $definition->aliases,
            description: $definition->description,
            usage: $definition->usage,
            acceptsArguments: $definition->acceptsArguments,
        );

        $slashHandler = new ExtensionSlashCommandHandler($handler);

        $this->commandCatalog->register($metadata, $slashHandler);
    }
}
