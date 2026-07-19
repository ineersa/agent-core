<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Runtime\SubagentLiveMainReturn;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;

final class AgentsMainCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly TuiSessionState $state,
        private readonly ChatScreen $screen,
        private readonly QuestionController $questionController,
        private readonly ?AgentSessionClient $client = null,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        if (!$this->state->subagentLiveView->active) {
            return new NoOp();
        }

        SubagentLiveMainReturn::returnToMain($this->state, $this->screen, $this->client);
        // Visual only: keep coordinator request pending for re-enter child.
        $this->questionController->close();

        return new NoOp();
    }
}
