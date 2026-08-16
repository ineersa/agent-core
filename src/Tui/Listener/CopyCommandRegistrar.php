<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /copy (alias: /cp) slash command.
 *
 * Command metadata is registered once per process via
 * {@see registerCatalog()}; each session binds a fresh handler.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class CopyCommandRegistrar implements TuiListenerRegistrar, SlashCommandCatalogRegistrar
{
    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        $catalog->registerMetadata(new CommandMetadata(
            name: 'copy',
            aliases: ['cp'],
            description: 'Copy the last model output to the clipboard',
            usage: '/copy',
        ));
    }

    public function register(TuiRuntimeContext $context): void
    {
        $handler = new CopyCommandHandler($context->state);

        $context->sessionServices->commandRegistry->bind('copy', $handler);
    }
}
