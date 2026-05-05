<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ApplyCommandHandler implements RunMessageHandler
{
    public function __construct(
        private CommandStoreInterface $commandStore,
        private CommandRouter $commandRouter,
        private CommandMailboxPolicy $commandMailboxPolicy,
        private RunMessageStateTools $stateTools,
        private int $maxPendingCommands = 100,
        private ?MessageBusInterface $commandBus = null,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof ApplyCommand;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof ApplyCommand) {
            throw new \InvalidArgumentException('ApplyCommandHandler can only handle ApplyCommand messages.');
        }

        $runId = $message->runId();

        if ($this->commandStore->has($runId, $message->idempotencyKey())) {
            return new HandlerResult();
        }

        $routedCommand = $this->commandRouter->route($message);

        if ($routedCommand->isRejected()) {
            $reason = \is_string($routedCommand->reason) ? $routedCommand->reason : 'Command rejected by router.';

            return $this->rejectCommand($state, $message, $reason);
        }

        if (CoreCommandKind::Cancel !== $message->kind && $this->commandStore->countPending($runId) >= $this->maxPendingCommands) {
            return $this->rejectCommand(
                $state,
                $message,
                \sprintf('Pending command mailbox cap (%d) exceeded for run.', $this->maxPendingCommands),
            );
        }

        if (RunStatus::Cancelled === $state->status) {
            return $this->rejectCommand($state, $message, 'Run is already cancelled.');
        }

        if (RunStatus::Cancelling === $state->status
            && !$this->commandMailboxPolicy->isCancelSafeExtensionCommand($message->kind, $routedCommand->options)) {
            return $this->rejectCommand(
                $state,
                $message,
                \sprintf('Command "%s" rejected because cancellation is in progress.', $message->kind),
            );
        }

        if (CoreCommandKind::Cancel === $message->kind) {
            return $this->applyCancelCommand($state, $message);
        }

        if (CoreCommandKind::Continue === $message->kind) {
            return $this->applyContinueCommand($state, $message, $routedCommand->options);
        }

        if (CoreCommandKind::HumanResponse === $message->kind) {
            return $this->applyHumanResponseCommand($state, $message, $routedCommand->options);
        }

        $pendingCommand = new PendingCommand(
            runId: $runId,
            kind: $message->kind,
            idempotencyKey: $message->idempotencyKey(),
            payload: $message->payload,
            options: new CommandCancellationOptions(
                safe: true === ($routedCommand->options['cancel_safe'] ?? false),
            ),
        );

        if (!$this->commandStore->enqueue($pendingCommand)) {
            return new HandlerResult();
        }

        $nextState = new RunState(
            runId: $state->runId,
            status: $state->status,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + 1,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
            retryableFailure: $state->retryableFailure,
        );

        $queuedEvent = $this->stateTools->event(
            runId: $runId,
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: 'agent_command_queued',
            payload: [
                'kind' => $message->kind,
                'idempotency_key' => $message->idempotencyKey(),
                'options' => $routedCommand->options,
            ],
        );

        return new HandlerResult(
            nextState: $nextState,
            events: [$queuedEvent],
        );
    }

    private function rejectCommand(RunState $state, ApplyCommand $message, string $reason): HandlerResult
    {
        $runId = $message->runId();
        $this->commandStore->markRejected($runId, $message->idempotencyKey(), $reason);

        $nextState = new RunState(
            runId: $state->runId,
            status: $state->status,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + 1,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $reason,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
            retryableFailure: $state->retryableFailure,
        );

        $event = $this->stateTools->event(
            runId: $runId,
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: 'agent_command_rejected',
            payload: [
                'kind' => $message->kind,
                'reason' => $reason,
                'idempotency_key' => $message->idempotencyKey(),
            ],
        );

        return new HandlerResult(
            nextState: $nextState,
            events: [$event],
        );
    }

    private function applyCancelCommand(RunState $state, ApplyCommand $message): HandlerResult
    {
        $runId = $message->runId();
        $reason = \is_string($message->payload['reason'] ?? null)
            ? $message->payload['reason']
            : 'Run cancelled by command.';

        $this->commandStore->markApplied($runId, $message->idempotencyKey());
        $rejectedContinueCommands = $this->commandStore->rejectPendingByKind(
            $runId,
            CoreCommandKind::Continue,
            'Rejected because cancel command was accepted.',
        );

        $eventSpecs = [[
            'type' => 'agent_command_applied',
            'payload' => [
                'kind' => $message->kind,
                'idempotency_key' => $message->idempotencyKey(),
                'options' => [],
            ],
        ]];

        foreach ($rejectedContinueCommands as $rejectedContinueCommand) {
            $eventSpecs[] = [
                'type' => 'agent_command_rejected',
                'payload' => [
                    'kind' => CoreCommandKind::Continue,
                    'idempotency_key' => $rejectedContinueCommand->idempotencyKey,
                    'reason' => 'Rejected because cancel command was accepted.',
                ],
            ];
        }

        $events = $this->stateTools->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

        $nextState = new RunState(
            runId: $state->runId,
            status: RunStatus::Cancelling,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + \count($events),
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $reason,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
            retryableFailure: false,
        );

        return new HandlerResult(
            nextState: $nextState,
            events: $events,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyContinueCommand(RunState $state, ApplyCommand $message, array $options): HandlerResult
    {
        $reason = $this->commandMailboxPolicy->continueRejectionReason($state);
        if (null !== $reason) {
            return $this->rejectCommand($state, $message, $reason);
        }

        $runId = $message->runId();
        $this->commandStore->markApplied($runId, $message->idempotencyKey());

        $nextState = new RunState(
            runId: $state->runId,
            status: RunStatus::Running,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + 1,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: null,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
            retryableFailure: false,
        );

        $event = $this->stateTools->event(
            runId: $runId,
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: 'agent_command_applied',
            payload: [
                'kind' => $message->kind,
                'idempotency_key' => $message->idempotencyKey(),
                'options' => $options,
            ],
        );

        $postCommit = [];
        $followUpAdvance = $this->followUpAdvanceCallback($runId, 'continue');
        if (null !== $followUpAdvance) {
            $postCommit[] = $followUpAdvance;
        }

        return new HandlerResult(
            nextState: $nextState,
            events: [$event],
            postCommit: $postCommit,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyHumanResponseCommand(RunState $state, ApplyCommand $message, array $options): HandlerResult
    {
        if (RunStatus::WaitingHuman !== $state->status) {
            return $this->rejectCommand(
                $state,
                $message,
                'human_response command is only allowed while run is waiting for human input.',
            );
        }

        $humanResponseMessage = $this->stateTools->humanResponseMessage($message->payload);
        if (null === $humanResponseMessage) {
            return $this->rejectCommand($state, $message, 'Invalid human_response payload: missing answer.');
        }

        $runId = $message->runId();
        $this->commandStore->markApplied($runId, $message->idempotencyKey());

        $messages = $state->messages;
        $messages[] = $humanResponseMessage;

        $nextState = new RunState(
            runId: $state->runId,
            status: RunStatus::Running,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + 1,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: null,
            messages: $messages,
            activeStepId: $state->activeStepId,
            retryableFailure: false,
        );

        $event = $this->stateTools->event(
            runId: $runId,
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: 'agent_command_applied',
            payload: [
                'kind' => $message->kind,
                'idempotency_key' => $message->idempotencyKey(),
                'question_id' => \is_string($message->payload['question_id'] ?? null) ? $message->payload['question_id'] : null,
                'options' => $options,
            ],
        );

        $postCommit = [];
        $followUpAdvance = $this->followUpAdvanceCallback($runId, 'human-response');
        if (null !== $followUpAdvance) {
            $postCommit[] = $followUpAdvance;
        }

        return new HandlerResult(
            nextState: $nextState,
            events: [$event],
            postCommit: $postCommit,
        );
    }

    private function followUpAdvanceCallback(string $runId, string $prefix): ?callable
    {
        if (null === $this->commandBus) {
            return null;
        }

        return function () use ($runId, $prefix): void {
            $stepId = \sprintf('%s-%d', $prefix, hrtime(true));

            try {
                $this->commandBus->dispatch(new AdvanceRun(
                    runId: $runId,
                    turnNo: 0,
                    stepId: $stepId,
                    attempt: 1,
                    idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $stepId)),
                ));
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to dispatch follow-up AdvanceRun command.', previous: $exception);
            }
        };
    }
}
