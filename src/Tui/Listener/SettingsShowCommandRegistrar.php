<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /settings-show slash command.
 *
 * Command metadata is registered once per process via
 * {@see registerCatalog()}; the handler is process-owned and stateless.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class SettingsShowCommandRegistrar implements TuiListenerRegistrar, SlashCommandCatalogRegistrar
{
    public function __construct(
        private readonly SettingsShowCommandHandler $handler,
    ) {
    }

    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        $catalog->registerMetadata(new CommandMetadata(
            name: 'settings-show',
            description: 'Show effective settings with source and descriptions',
            usage: '/settings-show [group-or-path]',
            acceptsArguments: true,
        ));
    }

    public function register(TuiRuntimeContext $context): void
    {
        $context->sessionServices->commandRegistry->bind('settings-show', $this->handler);
    }
}
