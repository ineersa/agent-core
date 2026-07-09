<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\Repair\SessionRepairService;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

final class RepairCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
        private readonly SessionRepairService $repairService,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $handler = new RepairCommandHandler($context->state, $this->repairService);

        if ($this->commandRegistry->has('repair')) {
            $this->commandRegistry->setHandler('repair', $handler);

            return;
        }

        $this->commandRegistry->register(
            new CommandMetadata(
                name: 'repair',
                aliases: [],
                description: 'Repair corrupted session event history for the current session',
                usage: '/repair',
                acceptsArguments: true,
            ),
            $handler,
        );
    }
}
