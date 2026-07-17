<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /settings-show slash command.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class SettingsShowCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
        private readonly SettingsShowCommandHandler $handler,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        if ($this->commandRegistry->has('settings-show')) {
            $this->commandRegistry->setHandler('settings-show', $this->handler);

            return;
        }

        $this->commandRegistry->register(
            new CommandMetadata(
                name: 'settings-show',
                description: 'Show effective settings with source and descriptions',
                usage: '/settings-show [group-or-path]',
                acceptsArguments: true,
            ),
            $this->handler,
        );
    }
}
