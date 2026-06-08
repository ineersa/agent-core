<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /copy (alias: /cp) slash command.
 *
 * Uses the idempotent registration pattern: if the command is already
 * registered (e.g. via multiple context construction), the handler is
 * replaced rather than throwing.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class CopyCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $handler = new CopyCommandHandler($context->state);

        if ($this->commandRegistry->has('copy')) {
            $this->commandRegistry->setHandler('copy', $handler);

            return;
        }

        $this->commandRegistry->register(
            new CommandMetadata(
                name: 'copy',
                aliases: ['cp'],
                description: 'Copy the last model output to the clipboard',
                usage: '/copy',
            ),
            $handler,
        );
    }
}
