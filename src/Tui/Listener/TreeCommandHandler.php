<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Picker\TreePickerController;

/**
 * Handles the /tree slash command.
 *
 * Opens the read-only turn tree picker.  The picker displays the
 * current session's turn tree from canonical events.jsonl.  Enter
 * closes the picker without mutating state.
 *
 * Lives in TuiListener (not TuiCommand) because it depends on
 * TurnTreeProviderInterface from AppRuntimeContract and
 * TreePickerController from TuiPicker, which TuiCommand cannot
 * import per deptrac rules.
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
