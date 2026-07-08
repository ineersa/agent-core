<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /export (alias: /exp) slash command.
 *
 * Uses the idempotent registration pattern: if the command is already
 * registered (e.g. via multiple context construction), the handler is
 * replaced rather than throwing.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class ExportCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
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

        if ($this->commandRegistry->has('export')) {
            $this->commandRegistry->setHandler('export', $handler);

            return;
        }

        $this->commandRegistry->register(
            new CommandMetadata(
                name: 'export',
                aliases: ['exp'],
                description: 'Export the current session transcript to a file',
                usage: '/export [path]',
                acceptsArguments: true,
            ),
            $handler,
        );
    }
}
