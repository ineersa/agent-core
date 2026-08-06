<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Picker\TreePickerController;

/**
 * Handles the /history slash command.
 *
 * Opens the linear user-prompt history picker. Enter positions context
 * before the selected prompt and populates the editor.
 */
final class TreeCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly TreePickerController $pickerController,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $this->pickerController->open();

        return new NoOp();
    }
}
