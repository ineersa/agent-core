<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\BuiltInSlashCommands;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /export (alias: /exp) slash command.
 *
 * Command metadata is static data in {@see BuiltInSlashCommands}; each
 * session binds a fresh handler.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class ExportCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SessionEventsExportService $exportService,
    ) {
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
