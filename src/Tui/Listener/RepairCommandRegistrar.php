<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Psr\Log\LoggerInterface;

/**
 * Registers the /repair slash command for the active session.
 *
 * Command metadata is registered once per process via
 * {@see registerCatalog()}; each session binds a fresh handler.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class RepairCommandRegistrar implements TuiListenerRegistrar, SlashCommandCatalogRegistrar
{
    public function __construct(
        private readonly SessionRepairServiceInterface $repairService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        $catalog->registerMetadata(new CommandMetadata(
            name: 'repair',
            aliases: [],
            description: 'Repair stale cancellation for the active session',
            usage: '/repair',
            acceptsArguments: false,
        ));
    }

    public function register(TuiRuntimeContext $context): void
    {
        $handler = new RepairCommandHandler($this->repairService, $context->state, $this->logger);

        $context->sessionServices->commandRegistry->bind('repair', $handler);
    }
}
