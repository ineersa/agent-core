<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /export (alias: /exp) slash command.
 *
 * Command metadata is registered once per process via
 * {@see registerCatalog()}; each session binds a fresh handler.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class ExportCommandRegistrar implements TuiListenerRegistrar, SlashCommandCatalogRegistrar
{
    public function __construct(
        private readonly SessionEventsExportService $exportService,
    ) {
    }

    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        $catalog->registerMetadata(new CommandMetadata(
            name: 'export',
            aliases: ['exp'],
            description: 'Export the current session transcript to a file',
            usage: '/export [path]',
            acceptsArguments: true,
        ));
    }

    public function register(TuiRuntimeContext $context): void
    {
        $handler = new ExportCommandHandler(
            $context->state,
            $context->sessionStore,
            $this->exportService,
        );

        $context->sessionServices->commandRegistry->bind('export', $handler);
    }
}
