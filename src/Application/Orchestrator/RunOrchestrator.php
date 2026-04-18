<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\OutboxProjector;
use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Reducer\RunReducer;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\PromptStateStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class RunOrchestrator
{
    public function __construct(
        private RunStoreInterface $runStore,
        private EventStoreInterface $eventStore,
        private CommandStoreInterface $commandStore,
        private RunReducer $reducer,
        private StepDispatcher $stepDispatcher,
        private CommandRouter $commandRouter,
        private OutboxProjector $outboxProjector,
        private PromptStateStoreInterface $promptStateStore,
        private FilesystemOperator $runLogFilesystem,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onStartRun(StartRun $message): void
    {
        $this->transition($message->runId(), $message);
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onApplyCommand(ApplyCommand $message): void
    {
        $routedCommand = $this->commandRouter->route($message);

        if ($routedCommand->isRejected()) {
            $this->commandStore->markRejected($message->runId(), $message->idempotencyKey(), (string) $routedCommand->reason);

            $state = $this->runStore->get($message->runId()) ?? RunState::queued($message->runId());
            $nextSeq = $state->lastSeq + 1;
            $nextState = new RunState(
                runId: $state->runId,
                status: $state->status,
                version: $state->version + 1,
                turnNo: $state->turnNo,
                lastSeq: $nextSeq,
                isStreaming: $state->isStreaming,
                streamingMessage: $state->streamingMessage,
                pendingToolCalls: $state->pendingToolCalls,
                errorMessage: \is_string($routedCommand->reason) ? $routedCommand->reason : $state->errorMessage,
                messages: $state->messages,
            );
            $this->runStore->save($nextState);

            $event = new RunEvent(
                runId: $message->runId(),
                seq: $nextSeq,
                turnNo: $state->turnNo,
                type: 'agent_command_rejected',
                payload: [
                    'kind' => $message->kind,
                    'reason' => $routedCommand->reason,
                    'idempotency_key' => $message->idempotencyKey(),
                ],
            );
            $this->eventStore->append($event);
            $this->outboxProjector->project([$event]);

            $replayService = new ReplayService(
                $this->eventStore,
                new RunLogReader($this->runLogFilesystem),
                $this->promptStateStore,
            );
            $replayService->rebuildHotPromptState($message->runId());

            return;
        }

        $this->commandStore->markApplied($message->runId(), $message->idempotencyKey());
        $this->transition($message->runId(), $message);
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onAdvanceRun(AdvanceRun $message): void
    {
        $this->transition($message->runId(), $message);
    }

    private function transition(string $runId, object $command): void
    {
        $state = $this->runStore->get($runId) ?? RunState::queued($runId);
        $result = $this->reducer->reduce($state, $command);

        $this->runStore->save($result->state);
        $this->stepDispatcher->dispatchEffects($result->effects);
    }
}
