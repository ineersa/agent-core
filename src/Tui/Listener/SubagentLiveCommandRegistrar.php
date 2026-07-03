<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

final class SubagentLiveCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
        private readonly SubagentLivePickerController $pickerController,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $this->pickerController->setRuntimeRefs($context->tui, $context->screen, $context->state);

        $liveHandler = new AgentsLiveCommandHandler($this->pickerController);
        if ($this->commandRegistry->has('agents-live')) {
            $this->commandRegistry->setHandler('agents-live', $liveHandler);
        } else {
            $this->commandRegistry->register(
                new CommandMetadata(
                    name: 'agents-live',
                    description: 'Open interactive live view for a subagent',
                    usage: '/agents-live',
                    acceptsArguments: false,
                ),
                $liveHandler,
            );
        }

        $mainHandler = new AgentsMainCommandHandler($context->state, $context->screen);

        if ($this->commandRegistry->has('agents-main')) {
            $this->commandRegistry->setHandler('agents-main', $mainHandler);
        } else {
            $this->commandRegistry->register(
                new CommandMetadata(
                    name: 'agents-main',
                    aliases: ['main'],
                    description: 'Return from subagent live view to the main session',
                    usage: '/agents-main',
                    acceptsArguments: false,
                ),
                $mainHandler,
            );
        }
    }
}
