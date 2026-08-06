<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Picker\HistoryPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers the /history slash command.
 */
final class HistoryCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
        private readonly HistoryProviderInterface $historyProvider,
        private readonly TuiSessionSwitchServiceInterface $switcher,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $tui = $context->tui;
        $screen = $context->screen;
        $state = $context->state;

        $picker = new HistoryPickerController($this->historyProvider, $this->switcher);
        $picker->setRuntimeRefs($tui, $screen, $state);

        $handler = new HistoryCommandHandler($picker);

        if ($this->commandRegistry->has('history')) {
            $this->commandRegistry->setHandler('history', $handler);
        } else {
            $this->commandRegistry->register(
                new CommandMetadata(
                    name: 'history',
                    description: 'Show session user-prompt history — Enter to edit prompt, Esc to close',
                    usage: '/history',
                    acceptsArguments: false,
                ),
                $handler,
            );
        }
    }
}
