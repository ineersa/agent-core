<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
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

        $this->state->subagentLiveView->exit();
        $this->screen->setStatus('agents-live', '');
        // Parent transcript kept updating in memory while live view was active.
        $this->screen->setTranscriptBlocks($this->state->transcript);
        $this->screen->setWorkingMessage('');
        $this->screen->requestRender(true);

        return new NoOp();
    }
}
