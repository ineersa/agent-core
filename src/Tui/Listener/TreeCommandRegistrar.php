<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Picker\TreePickerController;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers /tree slash command in the TUI.
 *
 * Wires TreePickerController with per-iteration runtime refs and
 * registers the /tree command idempotently.
 */
final class TreeCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
        private readonly TurnTreeProviderInterface $treeProvider,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $tui = $context->tui;
        $screen = $context->screen;
        $state = $context->state;

        // Create picker controller and wire per-iteration runtime refs
        $picker = new TreePickerController($this->treeProvider);
        $picker->setRuntimeRefs($tui, $screen, $state);

        // Create handler
        $handler = new TreeCommandHandler($picker);

        // Register /tree command (idempotent)
        if ($this->commandRegistry->has('tree')) {
            $this->commandRegistry->setHandler('tree', $handler);
        } else {
            $this->commandRegistry->register(
                new CommandMetadata(
                    name: 'tree',
                    description: 'Show session turn tree (read-only)',
                    usage: '/tree',
                    acceptsArguments: false,
                ),
                $handler,
            );
        }
    }
}
