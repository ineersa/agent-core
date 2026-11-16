<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\Goal\Exception\GoalAccessException;
use Ineersa\CodingAgent\Session\Goal\SessionGoalService;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class GoalCommandHandler implements SlashCommandHandler
{
    public function __construct(private SessionGoalService $goals, private TuiSessionState $state, private ChatScreen $screen)
    {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $text = trim($command->args);
        try {
            $goal = '' === $this->state->sessionId ? $this->state->pendingGoal : $this->goals->get($this->state->sessionId);
            if ('' === $text) {
                return new TranscriptMessage(null === $goal
                    ? 'No session goal. Set one with /goal <objective>.'
                    : "Session goal:\n".$goal."\n\n/goal edit · /goal clear (or cancel)", 'system');
            }
            if ('edit' === strtolower($text)) {
                if (null === $goal) {
                    return new TranscriptMessage('No session goal to edit. Use /goal <objective>.', 'system');
                }
                $this->screen->promptEditor()->setText('/goal '.$goal);

                return new NoOp();
            }
            if (\in_array(strtolower($text), ['clear', 'cancel'], true)) {
                if ('' !== $this->state->sessionId) {
                    $this->goals->set($this->state->sessionId, null);
                }
                $this->state->pendingGoal = null;

                return new TranscriptMessage('Session goal cleared. Already running work uses the normal interrupt controls.', 'system');
            }
            $goal = $this->goals->validateObjective($text);
            if ('' === $this->state->sessionId) {
                // SubmitListener promotes the draft and persists this before its normal start().
                $this->state->pendingGoal = $goal;
            } else {
                $this->goals->set($this->state->sessionId, $goal);
            }

            return new DispatchRuntime("Pursue this session goal:\n".$goal."\nVerify the full objective against current evidence before clearing it with clear_goal. A final answer alone does not complete the goal.");
        } catch (GoalAccessException|ValidationFailedException $exception) {
            return new TranscriptMessage($exception->getMessage(), 'system', 'error');
        }
    }
}
