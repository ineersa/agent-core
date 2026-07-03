<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SubagentLiveInputPolicy;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Psr\Log\LoggerInterface;

final class AgentsCancelCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly TuiSessionState $state,
        private readonly ChatScreen $screen,
        private readonly AgentSessionClient $client,
        private readonly SubagentLiveInputPolicy $policy,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $live = $this->state->subagentLiveView;
        if (!$live->active || null === $live->selected) {
            $this->screen->setStatus('agents-live', $this->policy->childCancelUnavailableMessage());

            return new NoOp();
        }

        $child = $live->selected;
        if (!self::isChildCancellable($child, $live->childActivity)) {
            $this->screen->setStatus('agents-live', $this->policy->childCancelUnavailableMessage());

            return new NoOp();
        }

        $this->logger->info('agents-cancel child subagent requested', [
            'component' => 'agents_cancel_command',
            'event_type' => 'subagent_live_child_cancel_requested',
            'run_id' => $child->agentRunId,
            'artifact_id' => $child->artifactId,
            'agent_name' => $child->agentName,
        ]);

        try {
            $this->client->cancel($child->agentRunId);
        } catch (\Throwable $e) {
            $this->logger->error('agents-cancel child command failed', [
                'component' => 'agents_cancel_command',
                'event_type' => 'subagent_live_child_cancel_failed',
                'run_id' => $child->agentRunId,
                'artifact_id' => $child->artifactId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
            $this->screen->setStatus('agents-live', 'Child cancel failed: '.$e->getMessage());

            return new NoOp();
        }

        $live->childActivity = RunActivityStateEnum::Cancelling;
        $this->screen->setWorkingMessage(\sprintf('Cancelling child %s...', $child->agentName));
        $this->screen->setStatus('agents-live', \sprintf('Cancelling child %s (%s).', $child->agentName, $child->artifactId));

        return new NoOp();
    }

    private static function isChildCancellable(SubagentLiveChildDTO $child, RunActivityStateEnum $childActivity): bool
    {
        if ($child->isTerminal()) {
            return false;
        }

        return $childActivity->isActive() || RunActivityStateEnum::Cancelling === $childActivity;
    }
}
