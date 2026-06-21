<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /compact slash command.
 *
 * Uses the idempotent registration pattern: if the command is already
 * registered, the handler is replaced rather than throwing.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class CompactCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $handler = new CompactCommandHandler($context->client, $context->state);

        if ($this->commandRegistry->has('compact')) {
            $this->commandRegistry->setHandler('compact', $handler);

            return;
        }

        $this->commandRegistry->register(
            new CommandMetadata(
                name: 'compact',
                aliases: ['cmp'],
                description: 'Compact the conversation to reduce token usage',
                usage: '/compact [custom instructions]',
                acceptsArguments: true,
            ),
            $handler,
        );
    }
}
