<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ApplyCommandHandler implements RunMessageHandler
{
    /** @var list<CoreCommandKind::*> */
    private const REJECT_ON_CANCEL_KINDS = [
        CoreCommandKind::Steer,
        CoreCommandKind::FollowUp,
        CoreCommandKind::Continue,
    ];

    public function __construct(
        private CommandStoreInterface $commandStore,
        private CommandRouter $commandRouter,
        private CommandMailboxPolicy $commandMailboxPolicy,
        private EventFactory $eventFactory,
        private AgentMessageNormalizer $messageNormalizer,
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

        if (RunStatus::Cancelled === $state->status && CoreCommandKind::FollowUp !== $message->kind) {
            return $this->rejectCommand($state, $message, 'Run is already cancelled.');
        }

        if (RunStatus::Cancelling === $state->status
            && CoreCommandKind::Cancel !== $message->kind
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

        if (CoreCommandKind::Compact === $message->kind) {
            return $this->applyCompactCommand($state, $message);
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

        $queuedEvent = $this->eventFactory->event(
            runId: $runId,
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: RunEventTypeEnum::AgentCommandQueued->value,
            payload: [
                'kind' => $message->kind,
                'idempotency_key' => $message->idempotencyKey(),
                'options' => $routedCommand->options,
            ],
        );

        // Queue-drain boundary: only dispatch an immediate AdvanceRun when
        // the run is at a safe/terminal boundary (Completed, Failed,
        // Cancelled, WaitingHuman).  If the run is active (Running or
        // Cancelling), the queued command will be drained at the next
        // stop boundary (no-tool-call result) or turn-start boundary
        // (after tool batch completes).  Without this guard, a steer/
        // follow-up during active model/tool work would immediately
        // dispatch AdvanceRun while the prompt tail contains unresolved
        // assistant tool_calls, causing the provider to reject the run
        // with "insufficient tool messages following tool_calls message".
        $postCommit = [];
        // Active runs (Running, Cancelling, or Compacting) queue the command
        // for the next safe boundary.  Non-active runs apply immediately.
        $isActive = \in_array($state->status, [RunStatus::Running, RunStatus::Cancelling, RunStatus::Compacting], true);
        if (!$isActive) {
            $followUpAdvance = $this->followUpAdvanceCallback($runId, $message->kind);
            if (null !== $followUpAdvance) {
                $postCommit[] = $followUpAdvance;
            }
        }

        return new HandlerResult(
            nextState: $nextState,
            events: [$queuedEvent],
            postCommit: $postCommit,
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

        $event = $this->eventFactory->event(
            runId: $runId,
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: RunEventTypeEnum::AgentCommandRejected->value,
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

        // Reject all pending queueable user-input commands (steer,
        // follow_up, continue) so stale queued commands are never
        // consumed after cancel/restart.  This prevents #152 where
        // queued steer/follow-up from before a cancel could be drained
        // unexpectedly after the run restarts.
        $rejectedKinds = self::REJECT_ON_CANCEL_KINDS;
        $rejectedCommands = [];
        foreach ($rejectedKinds as $kind) {
            $rejected = $this->commandStore->rejectPendingByKind(
                $runId,
                $kind,
                'Rejected because cancel command was accepted.',
            );
            $rejectedCommands = array_merge($rejectedCommands, $rejected);
        }

        $eventSpecs = [[
            'type' => RunEventTypeEnum::AgentCommandApplied->value,
            'payload' => [
                'kind' => $message->kind,
                'idempotency_key' => $message->idempotencyKey(),
                'options' => [],
            ],
        ]];

        foreach ($rejectedCommands as $rejectedCommand) {
            $eventSpecs[] = [
                'type' => RunEventTypeEnum::AgentCommandRejected->value,
                'payload' => [
                    'kind' => $rejectedCommand->kind,
                    'idempotency_key' => $rejectedCommand->idempotencyKey,
                    'reason' => 'Rejected because cancel command was accepted.',
                ],
            ];
        }

        // Idempotent cancel: when cancellation is already in progress,
        // accept the command without changing state (no version bump).
        // Repeated cancel during Cancelling should not be rejected.
        if (RunStatus::Cancelling === $state->status) {
            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, [[
                'type' => RunEventTypeEnum::AgentCommandApplied->value,
                'payload' => [
                    'kind' => $message->kind,
                    'idempotency_key' => $message->idempotencyKey(),
                    'options' => [],
                ],
            ]]);

            $noopState = new RunState(
                runId: $state->runId,
                status: $state->status,
                version: $state->version + 1,
                turnNo: $state->turnNo,
                lastSeq: $state->lastSeq + \count($events),
                isStreaming: $state->isStreaming,
                streamingMessage: $state->streamingMessage,
                pendingToolCalls: $state->pendingToolCalls,
                errorMessage: $state->errorMessage,
                messages: $state->messages,
                activeStepId: $state->activeStepId,
                retryableFailure: $state->retryableFailure,
            );

            return new HandlerResult(
                nextState: $noopState,
                events: $events,
            );
        }

        $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

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

        $event = $this->eventFactory->event(
            runId: $runId,
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: RunEventTypeEnum::AgentCommandApplied->value,
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

        $humanResponseMessage = $this->messageNormalizer->humanResponseMessage($message->payload);
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

        $humanResponseMessageArray = $humanResponseMessage->toArray();

        $event = $this->eventFactory->event(
            runId: $runId,
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: RunEventTypeEnum::AgentCommandApplied->value,
            payload: [
                'kind' => $message->kind,
                'idempotency_key' => $message->idempotencyKey(),
                'question_id' => \is_string($message->payload['question_id'] ?? null) ? $message->payload['question_id'] : null,
                'answer' => \is_string($message->payload['answer'] ?? null) ? $message->payload['answer'] : null,
                'message' => $humanResponseMessageArray,
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

    /**
     * Apply a compact command.
     *
     * Active run (Running / Cancelling): enqueue as PendingCommand for
     * mailbox drain at the next safe boundary (stop-boundary via
     * LlmStepResultHandler or turn-start via AdvanceRunHandler).
     * CommandMailboxPolicy will markApplied on drain.
     *
     * Non-active state (Completed / Failed / Cancelled / WaitingHuman /
     * Queued): mark applied immediately and dispatch CompactRun via
     * post-commit callback.  No enqueue so the command cannot be
     * drained again on a future mailbox cycle — mirroring
     * applyContinueCommand / applyHumanResponseCommand.
     */
    private function applyCompactCommand(RunState $state, ApplyCommand $message): HandlerResult
    {
        $runId = $message->runId();
        $isActive = \in_array($state->status, [RunStatus::Running, RunStatus::Cancelling, RunStatus::Compacting], true);

        if (!$isActive) {
            // Terminal/safe boundary: apply immediately.
            $this->commandStore->markApplied($runId, $message->idempotencyKey());

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

            $appliedEvent = $this->eventFactory->event(
                runId: $runId,
                seq: $nextState->lastSeq,
                turnNo: $nextState->turnNo,
                type: RunEventTypeEnum::AgentCommandApplied->value,
                payload: [
                    'kind' => $message->kind,
                    'idempotency_key' => $message->idempotencyKey(),
                    'options' => [],
                ],
            );

            $postCommit = [];
            $compactCallback = $this->compactCallback($runId, $message->payload['custom_instructions'] ?? null);
            if (null !== $compactCallback) {
                $postCommit[] = $compactCallback;
            }

            return new HandlerResult(
                nextState: $nextState,
                events: [$appliedEvent],
                postCommit: $postCommit,
            );
        }

        // Active run: enqueue for next safe-boundary drain.
        $pendingCommand = new PendingCommand(
            runId: $runId,
            kind: $message->kind,
            idempotencyKey: $message->idempotencyKey(),
            payload: $message->payload,
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

        $queuedEvent = $this->eventFactory->event(
            runId: $runId,
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: RunEventTypeEnum::AgentCommandQueued->value,
            payload: [
                'kind' => $message->kind,
                'idempotency_key' => $message->idempotencyKey(),
                'options' => [],
            ],
        );

        return new HandlerResult(
            nextState: $nextState,
            events: [$queuedEvent],
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

    private function compactCallback(string $runId, ?string $customInstructions = null): ?callable
    {
        if (null === $this->commandBus) {
            return null;
        }

        return function () use ($runId, $customInstructions): void {
            $stepId = \sprintf('compact-%d', hrtime(true));

            try {
                $this->commandBus->dispatch(new CompactRun(
                    runId: $runId,
                    turnNo: 0,
                    stepId: $stepId,
                    attempt: 1,
                    idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $stepId)),
                    trigger: 'manual',
                    customInstructions: $customInstructions,
                ));
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to dispatch CompactRun command.', previous: $exception);
            }
        };
    }
}
