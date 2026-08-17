<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /history slash command.
 *
 * Command metadata is registered once per process via
 * {@see registerCatalog()}; each session binds a fresh handler wired to
 * the session's history picker controller.
 */
final class HistoryCommandRegistrar implements TuiListenerRegistrar, SlashCommandCatalogRegistrar
{
    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        $catalog->registerMetadata(new CommandMetadata(
            name: 'history',
            description: 'Show session user-prompt history — Enter to edit prompt, Esc to close',
            usage: '/history',
            acceptsArguments: false,
        ));
    }

    public function register(TuiRuntimeContext $context): void
    {
        $picker = $context->sessionServices->historyPicker;
        $handler = new HistoryCommandHandler($picker);

        $context->sessionServices->commandRegistry->bind('history', $handler);
    }
}
