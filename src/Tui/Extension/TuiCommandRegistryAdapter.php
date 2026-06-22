<?php

declare(strict_types=1);

namespace Ineersa\Tui\Extension;

use Ineersa\Hatfield\ExtensionApi\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\CommandRegistryInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionCommandHandlerInterface;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
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

            public function handle(SlashCommand $command): CommandResult
            {
                $context = new class implements CommandContextInterface {
                    /** @var list<string> */
                    public array $messages = [];

                    /** @var int highest-severity level seen: 0=info, 1=success, 2=warning, 3=error */
                    public int $highestSeverity = 0;

                    public function notify(string $message, string $level = 'info'): void
                    {
                        $this->messages[] = $message;
                        $sev = match ($level) {
                            'error' => 3,
                            'warning' => 2,
                            'success' => 1,
                            default => 0,
                        };
                        if ($sev > $this->highestSeverity) {
                            $this->highestSeverity = $sev;
                        }
                    }
                };

                $this->extensionHandler->handle($command->args, $context);

                if ([] === $context->messages) {
                    return new NoOp();
                }

                $text = implode("\n", $context->messages);

                $role = 'system';
                $style = '';
                if ($context->highestSeverity >= 3) {
                    $role = 'error';
                    $style = 'error';
                } elseif ($context->highestSeverity >= 2) {
                    $style = 'accent';
                }

                return new TranscriptMessage($text, $role, $style);
            }
        };

        $this->slashCommandRegistry->register($metadata, $slashHandler);
    }
}
