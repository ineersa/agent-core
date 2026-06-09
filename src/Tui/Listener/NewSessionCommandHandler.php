<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;

/**
 * Handles the /new slash command.
 *
 * Requests a switch to a fresh lazy draft session via
 * {@see TuiSessionSwitchServiceInterface::requestNewDraft()}.
 * No DB row is created — the draft is promoted on first
 * normal message submission.
 */
final class NewSessionCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly TuiSessionSwitchServiceInterface $switch,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $this->switch->requestNewDraft();

        return new NoOp();
    }
}
