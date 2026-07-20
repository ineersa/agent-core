<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
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
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
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
        private ?ToolBatchCollector $toolBatchCollector = null,
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

        if (RunStatus::Cancelled === $state->status
            && !\in_array($message->kind, [CoreCommandKind::FollowUp, CoreCommandKind::AppendMessage], true)) {
            return $this->rejectCommand($state, $message, 'Run is already cancelled.');
        }

        if (RunStatus::Cancelling === $state->status
            && CoreCommandKind::Cancel !== $message->kind
            && !$this->commandMailboxPolicy->isCancelSafeExtensionCommand($message->kind, $routedCommand->options)) {
            if (CoreCommandKind::AppendMessage === $message->kind) {
                return $this->enqueueModelVisibleMessageCommand($state, $message, $routedCommand->options);
            }

            return $this->rejectCommand(
                $state,
                $message,
                \sprintf('Command "%s" rejected because cancellation is in progress.', $message->kind),
            );
        }

        if (CoreCommandKind::Cancel === $message->kind) {
            // Guard: cancelling an already-terminated run (Completed / Failed
            // without retryable failure) would transition to Cancelling with
            // no subsequent transition event (no RunCancelled or TurnCancelled
            // is emitted for a completed/failed run), leaving the
            // ActivityStateMachine permanently stuck in Cancelling (issue #183).
            //
            // Failed-with-retryableFailure is an exception: the run is recoverable
            // via Continue, and Cancel may be used to clean up stale state before
            // retry.  Reject the cancel for truly terminal states so the UI
            // reflects the correct state.
            if (\in_array($state->status, [RunStatus::Completed, RunStatus::Failed], true) && !$state->retryableFailure) {
                return $this->rejectCommand(
                    $state,
                    $message,
                    \sprintf('Cancel rejected: run is already in terminal state (%s).', $state->status->value),
                );
            }

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
            pendingHumanInputRequests: $state->pendingHumanInputRequests,
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
                // Serialized steer/follow_up text so RuntimeEventTranslator can emit
                // user.message_queued for immediate TUI pending feedback (mirrors
                // CommandMailboxPolicy including message on agent_command_applied).
                'message' => $message->payload['message'] ?? null,
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
        if (!$isActive && \in_array($message->kind, [CoreCommandKind::Steer, CoreCommandKind::FollowUp, CoreCommandKind::AppendMessage], true)) {
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
            pendingHumanInputRequests: $state->pendingHumanInputRequests,
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

        // Reject stale queued user-input commands after cancel (#152).
        // AppendMessage stays pending in the mailbox for post-cancel AdvanceRun drain.
        $rejectedCommands = [];
        $hasPendingAppendMessage = false;
        $cancelRejectReason = 'Rejected because cancel command was accepted.';

        foreach ($this->commandStore->pending($runId) as $pendingCommand) {
            if (CoreCommandKind::AppendMessage === $pendingCommand->kind) {
                $hasPendingAppendMessage = true;

                continue;
            }

            if (!\in_array($pendingCommand->kind, self::REJECT_ON_CANCEL_KINDS, true)) {
                continue;
            }

            $this->commandStore->markRejected($runId, $pendingCommand->idempotencyKey, $cancelRejectReason);
            $rejectedCommands[] = $pendingCommand;
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
                    'reason' => $cancelRejectReason,
                ],
            ];
        }

        // Idempotent cancel: when cancellation is already in progress,
        // accept the command without changing state (no version bump).
        // Repeated cancel during Cancelling should not be rejected.
        if (RunStatus::Cancelling === $state->status) {
            if (!self::hasActiveCancellationWork($state)) {
                $terminalSpecs = [[
                    'type' => RunEventTypeEnum::AgentCommandApplied->value,
                    'payload' => [
                        'kind' => $message->kind,
                        'idempotency_key' => $message->idempotencyKey(),
                        'options' => [],
                    ],
                ], [
                    'type' => RunEventTypeEnum::AgentEnd->value,
                    'payload' => [
                        'reason' => 'cancelled',
                    ],
                ]];
                $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $terminalSpecs);

                $postCommit = [];
                if ($hasPendingAppendMessage) {
                    $followUpAdvance = $this->followUpAdvanceCallback($runId, 'post-cancel-advance');
                    if (null !== $followUpAdvance) {
                        $postCommit[] = $followUpAdvance;
                    }
                }

                return new HandlerResult(
                    nextState: new RunState(
                        runId: $state->runId,
                        status: RunStatus::Cancelled,
                        version: $state->version + 1,
                        turnNo: $state->turnNo,
                        lastSeq: $state->lastSeq + \count($events),
                        isStreaming: false,
                        streamingMessage: null,
                        pendingToolCalls: [],
                        errorMessage: $reason,
                        messages: $state->messages,
                        activeStepId: null,
                        retryableFailure: false,
                        pendingHumanInputRequests: $state->pendingHumanInputRequests,
                    ),
                    events: $events,
                    postCommit: $postCommit,
                );
            }

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
                pendingHumanInputRequests: $state->pendingHumanInputRequests,
            );

            return new HandlerResult(
                nextState: $noopState,
                events: $events,
            );
        }

        $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

        // When there is no active work to finish cancellation (not streaming,
        // no unresolved tool calls), terminalize immediately
        // to Cancelled.  Otherwise the run can get stuck Cancelling with no
        // later result/effect to transition it out — the classic cancel hang.
        if (!self::hasActiveCancellationWork($state)) {
            // No active work — terminalize to Cancelled with AgentEnd.
            // Reuse the already-built $eventSpecs (applied + stale rejections)
            // and append the agent_end event at the correct sequence number.
            $eventSpecs[] = [
                'type' => RunEventTypeEnum::AgentEnd->value,
                'payload' => [
                    'reason' => 'cancelled',
                ],
            ];

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

            $nextState = new RunState(
                runId: $state->runId,
                status: RunStatus::Cancelled,
                version: $state->version + 1,
                turnNo: $state->turnNo,
                lastSeq: $state->lastSeq + \count($events),
                isStreaming: $state->isStreaming,
                streamingMessage: $state->streamingMessage,
                pendingToolCalls: $state->pendingToolCalls,
                errorMessage: $reason,
                messages: $state->messages,
                activeStepId: null,
                retryableFailure: false,
                pendingHumanInputRequests: $state->pendingHumanInputRequests,
            );

            $postCommit = [];
            if ($hasPendingAppendMessage) {
                $followUpAdvance = $this->followUpAdvanceCallback($runId, 'post-cancel-advance');
                if (null !== $followUpAdvance) {
                    $postCommit[] = $followUpAdvance;
                }
            }

            return new HandlerResult(
                nextState: $nextState,
                events: $events,
                postCommit: $postCommit,
            );
        }

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
            pendingHumanInputRequests: $state->pendingHumanInputRequests,
        );

        return new HandlerResult(
            nextState: $nextState,
            events: $events,
        );
    }

    /**
     * Whether cancel must wait for in-flight LLM streaming or unresolved tool calls.
     *
     * A non-null activeStepId alone is not active work once all pending tool calls
     * are resolved and streaming has stopped (stale advance-after-tools step).
     */
    private static function hasActiveCancellationWork(RunState $state): bool
    {
        if ($state->isStreaming) {
            return true;
        }

        if (\in_array(false, $state->pendingToolCalls, true)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function enqueueModelVisibleMessageCommand(RunState $state, ApplyCommand $message, array $options): HandlerResult
    {
        $runId = $message->runId();

        $pendingCommand = new PendingCommand(
            runId: $runId,
            kind: $message->kind,
            idempotencyKey: $message->idempotencyKey(),
            payload: $message->payload,
            options: new CommandCancellationOptions(
                safe: true === ($options['cancel_safe'] ?? false),
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
            pendingHumanInputRequests: $state->pendingHumanInputRequests,
        );

        $queuedEvent = $this->eventFactory->event(
            runId: $runId,
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: RunEventTypeEnum::AgentCommandQueued->value,
            payload: [
                'kind' => $message->kind,
                'idempotency_key' => $message->idempotencyKey(),
                'options' => $options,
                'message' => $message->payload['message'] ?? null,
            ],
        );

        return new HandlerResult(
            nextState: $nextState,
            events: [$queuedEvent],
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

        $isAutoRetry = true === ($message->payload['auto_retry'] ?? false);
        $retryAttempts = $isAutoRetry ? $state->retryAttempts : 0;

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
            retryAttempts: $retryAttempts,
            pendingHumanInputRequests: $state->pendingHumanInputRequests,
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
                'payload' => $message->payload,
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
        $questionId = \is_string($message->payload['question_id'] ?? null) ? $message->payload['question_id'] : null;

        // Durable post-commit redrive: if RunState already advanced past WaitingHuman
        // for this human_response (commit succeeded, effect dispatch failed), re-emit
        // the exact ExecuteToolCall effect from durable batch answer metadata without
        // mutating RunState again. markApplied runs only after effects succeed.
        if (RunStatus::WaitingHuman !== $state->status
            && null !== $this->toolBatchCollector
            && null !== $questionId
            && \array_key_exists('answer', $message->payload)
        ) {
            $redrive = $this->tryRedriveToolCallHumanResponse($state, $message, $questionId);
            if (null !== $redrive) {
                return $redrive;
            }
        }

        if (RunStatus::WaitingHuman !== $state->status) {
            return $this->rejectCommand(
                $state,
                $message,
                'human_response command is only allowed while run is waiting for human input.',
            );
        }

        $activeRequest = $state->pendingHumanInputRequests[0] ?? null;
        if (null === $activeRequest) {
            return $this->rejectCommand(
                $state,
                $message,
                'human_response rejected: no pending human-input request.',
            );
        }
        if (null === $questionId || $questionId !== $activeRequest->questionId) {
            return $this->rejectCommand(
                $state,
                $message,
                \sprintf(
                    'human_response rejected: question_id does not match the active pending request (expected "%s").',
                    $activeRequest->questionId,
                ),
            );
        }
        if (HumanInputContinuationKindEnum::ToolCall === $activeRequest->continuationKind) {
            return $this->applyToolCallHumanResponse($state, $message, $options, $activeRequest, $questionId);
        }

        if (HumanInputContinuationKindEnum::ModelTurn !== $activeRequest->continuationKind) {
            return $this->rejectCommand(
                $state,
                $message,
                'human_response rejected: unsupported human-input continuation kind.',
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

        $remainingRequests = array_values(\array_slice($state->pendingHumanInputRequests, 1));

        $nextState = new RunState(
            runId: $state->runId,
            status: [] !== $remainingRequests ? RunStatus::WaitingHuman : RunStatus::Running,
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
            pendingHumanInputRequests: $remainingRequests,
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
                'answer' => \array_key_exists('answer', $message->payload) ? $message->payload['answer'] : null,
                'message' => $humanResponseMessageArray,
                'options' => $options,
            ],
        );

        $postCommit = [];
        // Model-turn answers schedule AdvanceRun only when no further human requests remain.
        if ([] === $remainingRequests) {
            $followUpAdvance = $this->followUpAdvanceCallback($runId, 'human-response');
            if (null !== $followUpAdvance) {
                $postCommit[] = $followUpAdvance;
            }
        }

        return new HandlerResult(
            nextState: $nextState,
            events: [$event],
            postCommit: $postCommit,
        );
    }

    /**
     * Resume the exact suspended tool call after human answer.
     *
     * No model-visible human_response message. The typed answer is attached to the
     * stored ExecuteToolCall and requeued through ToolBatchCollector (capacity-aware).
     * Status stays WaitingHuman while more pending requests remain.
     *
     * @param array<string, mixed> $options
     */
    private function applyToolCallHumanResponse(
        RunState $state,
        ApplyCommand $message,
        array $options,
        PendingHumanInputRequestDTO $activeRequest,
        string $questionId,
    ): HandlerResult {
        if (null === $this->toolBatchCollector) {
            return $this->rejectCommand(
                $state,
                $message,
                'human_response rejected: tool-call continuation requires ToolBatchCollector.',
            );
        }

        if (!\array_key_exists('answer', $message->payload)) {
            return $this->rejectCommand($state, $message, 'Invalid human_response payload: missing answer.');
        }

        $resolved = $this->resolveToolCallContinuationRef($activeRequest, $state, $message->runId());
        if (null === $resolved) {
            return $this->rejectCommand(
                $state,
                $message,
                'human_response rejected: tool-call continuation_ref is incomplete or mismatched.',
            );
        }

        [$toolCallId, $stepId, $turnNo, $ref] = $resolved;

        $answer = new ToolCallHumanInputAnswerDTO(
            questionId: $questionId,
            answer: $message->payload['answer'],
            continuationRef: $ref,
            requestPayload: $activeRequest->payload,
        );

        try {
            $effects = $this->toolBatchCollector->resumeHumanInputAnswer(
                $message->runId(),
                $turnNo,
                $stepId,
                $toolCallId,
                $questionId,
                $answer,
            );
        } catch (\LogicException $exception) {
            return $this->rejectCommand(
                $state,
                $message,
                'human_response rejected: tool-call resume failed ('.$exception::class.').',
            );
        }

        $runId = $message->runId();
        $remainingRequests = array_values(\array_slice($state->pendingHumanInputRequests, 1));

        $nextState = new RunState(
            runId: $state->runId,
            status: [] !== $remainingRequests ? RunStatus::WaitingHuman : RunStatus::Running,
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
            pendingHumanInputRequests: $remainingRequests,
        );

        // Replay-complete AgentCommandApplied without model-visible message payload.
        $event = $this->eventFactory->event(
            runId: $runId,
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: RunEventTypeEnum::AgentCommandApplied->value,
            payload: [
                'kind' => $message->kind,
                'idempotency_key' => $message->idempotencyKey(),
                'question_id' => $questionId,
                'answer' => $message->payload['answer'],
                'continuation_kind' => HumanInputContinuationKindEnum::ToolCall->value,
                'tool_call_id' => $toolCallId,
                'options' => $options,
            ],
        );

        // markApplied only after postCommitEffects succeed so Messenger redelivery can
        // redrive the exact ExecuteToolCall from durable batch answer metadata.
        return new HandlerResult(
            nextState: $nextState,
            events: [$event],
            postCommitEffects: $effects,
            postCommit: [
                function () use ($runId, $message): void {
                    $this->commandStore->markApplied($runId, $message->idempotencyKey());
                },
            ],
        );
    }

    /**
     * No-state redrive path used when human_response is retried after RunState commit
     * but before commandStore markApplied / effect dispatch completed.
     */
    private function tryRedriveToolCallHumanResponse(
        RunState $state,
        ApplyCommand $message,
        string $questionId,
    ): ?HandlerResult {
        if (null === $this->toolBatchCollector) {
            return null;
        }

        $turnNo = $state->turnNo;
        $stepId = $state->activeStepId;
        if (null === $stepId || '' === $stepId) {
            return null;
        }

        try {
            $effects = $this->toolBatchCollector->redriveHumanInputAnswer(
                $message->runId(),
                $turnNo,
                $stepId,
                $questionId,
                $message->payload['answer'],
            );
        } catch (\LogicException) {
            return null;
        }

        $runId = $message->runId();

        return new HandlerResult(
            nextState: null,
            events: [],
            postCommitEffects: $effects,
            postCommit: [
                function () use ($runId, $message): void {
                    $this->commandStore->markApplied($runId, $message->idempotencyKey());
                },
            ],
        );
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: array<string, mixed>}|null
     */
    private function resolveToolCallContinuationRef(
        PendingHumanInputRequestDTO $activeRequest,
        RunState $state,
        string $runId,
    ): ?array {
        $ref = $activeRequest->continuationRef;
        if (!\is_array($ref)) {
            return null;
        }

        try {
            PendingHumanInputRequestDTO::assertToolCallContinuationRef($ref);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $toolCallId = $ref['tool_call_id'];
        $stepId = $ref['step_id'];
        $turnNo = $ref['turn_no'];
        $refRunId = $ref['run_id'];

        if (!\is_string($toolCallId) || !\is_string($stepId) || !\is_int($turnNo) || !\is_string($refRunId)) {
            return null;
        }

        if ($refRunId !== $runId || $refRunId !== $state->runId) {
            return null;
        }
        if ($turnNo !== $state->turnNo) {
            return null;
        }
        if (null !== $state->activeStepId && $stepId !== $state->activeStepId) {
            return null;
        }
        if (!\array_key_exists($toolCallId, $state->pendingToolCalls)) {
            return null;
        }

        return [$toolCallId, $stepId, $turnNo, $ref];
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
                pendingHumanInputRequests: $state->pendingHumanInputRequests,
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
            pendingHumanInputRequests: $state->pendingHumanInputRequests,
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
