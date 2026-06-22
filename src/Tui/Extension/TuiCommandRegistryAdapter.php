<?php

declare(strict_types=1);

namespace Ineersa\Tui\Extension;

use Ineersa\Hatfield\ExtensionApi\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\CommandRegistryInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionCommandHandlerInterface;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\TranscriptMessage;

/**
 * TUI-layer adapter that bridges extension-registered slash commands
 * into the SlashCommandRegistry.
 *
 * Implements the CommandRegistryInterface (public ExtensionApi contract)
 * and wraps each ExtensionCommandHandlerInterface into a SlashCommandHandler
 * so commands registered by extensions flow through the native TUI command
 * infrastructure.
 *
 * The notify() mechanism on CommandContextInterface translates to
 * TranscriptMessage results that appear in the chat transcript.
 */
final readonly class TuiCommandRegistryAdapter implements CommandRegistryInterface
{
    public function __construct(
        private SlashCommandRegistry $slashCommandRegistry,
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

        $slashHandler = new readonly class($handler) implements SlashCommandHandler {
            public function __construct(
                private ExtensionCommandHandlerInterface $extensionHandler,
            ) {
            }

            public function handle(SlashCommand $command): TranscriptMessage
            {
                $context = new class implements CommandContextInterface {
                    /** @var list<string> */
                    public array $messages = [];

                    public function notify(string $message, string $level = 'info'): void
                    {
                        $this->messages[] = $message;
                    }
                };

                $this->extensionHandler->handle($command->args, $context);

                if ([] === $context->messages) {
                    return new TranscriptMessage('', 'system');
                }

                return new TranscriptMessage(implode("\n", $context->messages), 'system');
            }
        };

        $this->slashCommandRegistry->register($metadata, $slashHandler);
    }
}
