<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveMainReturn;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;

final class AgentsMainCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly TuiSessionState $state,
        private readonly ChatScreen $screen,
        private readonly QuestionCoordinator $questionCoordinator,
        private readonly QuestionController $questionController,
        private readonly SubagentLiveChildViewPoller $childPoller,
        private readonly ?AgentSessionClient $client = null,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        if (!$this->state->subagentLiveView->active) {
            return new NoOp();
        }

        $selected = $this->state->subagentLiveView->selected;
        if (null !== $selected) {
            $this->questionCoordinator->removeForRun($selected->agentRunId);
            $this->questionController->close();
        }

        SubagentLiveMainReturn::returnToMain($this->state, $this->screen, $this->client);
        $this->childPoller->resetProjection();

        return new NoOp();
    }
}
