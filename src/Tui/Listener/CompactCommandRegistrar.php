<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /compact slash command.
 *
 * Command metadata is registered once per process via
 * {@see registerCatalog()}; each session binds a fresh handler.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class CompactCommandRegistrar implements TuiListenerRegistrar, SlashCommandCatalogRegistrar
{
    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        $catalog->registerMetadata(new CommandMetadata(
            name: 'compact',
            aliases: ['cmp'],
            description: 'Compact the conversation to reduce token usage',
            usage: '/compact [custom instructions]',
            acceptsArguments: true,
        ));
    }

    public function register(TuiRuntimeContext $context): void
    {
        $handler = new CompactCommandHandler($context->client, $context->state);

        $context->sessionServices->commandRegistry->bind('compact', $handler);
    }
}
