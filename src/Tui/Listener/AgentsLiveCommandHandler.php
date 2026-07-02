<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Picker\SubagentLivePickerController;

final class AgentsLiveCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly SubagentLivePickerController $pickerController,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $this->pickerController->open();

        return new NoOp();
    }
}
