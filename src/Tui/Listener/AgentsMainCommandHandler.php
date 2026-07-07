<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Runtime\SubagentLiveMainReturn;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;

final class AgentsMainCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly TuiSessionState $state,
        private readonly ChatScreen $screen,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        if (!$this->state->subagentLiveView->active) {
            return new NoOp();
        }

        SubagentLiveMainReturn::returnToMain($this->state, $this->screen);

        return new NoOp();
    }
}
