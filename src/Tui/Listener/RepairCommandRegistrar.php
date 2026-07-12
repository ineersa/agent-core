<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Psr\Log\LoggerInterface;

/**
 * Registers the /repair slash command for the active session.
 *
 * @internal Autowired via {@see TuiListenerRegistrar} and the `app.tui_listener` tag
 */
final class RepairCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
        private readonly SessionRepairServiceInterface $repairService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $handler = new RepairCommandHandler($this->repairService, $context->state, $this->logger);

        if ($this->commandRegistry->has('repair')) {
            $this->commandRegistry->setHandler('repair', $handler);

            return;
        }

        $this->commandRegistry->register(
            new CommandMetadata(
                name: 'repair',
                aliases: [],
                description: 'Repair stale cancellation for the active session',
                usage: '/repair',
                acceptsArguments: false,
            ),
            $handler,
        );
    }
}
