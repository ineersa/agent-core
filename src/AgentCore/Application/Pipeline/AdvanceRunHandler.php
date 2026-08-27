<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\AdvanceRunCallbackFactory;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Contract\Compaction\PreLlmCompactionGuardInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class AdvanceRunHandler implements RunMessageHandler
{
    public function __construct(
        private CommandMailboxPolicy $commandMailboxPolicy,
        private EventFactory $eventFactory,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
        private ?PreLlmCompactionGuardInterface $preLlmCompactionGuard = null,
        private ?MessageBusInterface $commandBus = null,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof AdvanceRun;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof AdvanceRun) {
            throw new \InvalidArgumentException('AdvanceRunHandler can only handle AdvanceRun messages.');
        }

        $runId = $message->runId();

        // AdvanceRun consumes the exact current turn. A prior committed
        // transition must stop before it drains commands queued afterward.
        if ($message->turnNo() !== $state->turnNo
            || $message->idempotencyKey() === $state->lastAppliedAdvanceKey) {
            return new HandlerResult();
        }

        // AdvanceRun is a successor token. Once it has committed a new active
        // operation, a redelivery must stop before it can drain newer mailbox
        // work or dispatch a second successor.
        if (null !== $state->currentOperation
            && $state->currentOperation->idempotencyKey !== $message->idempotencyKey()) {
            return new HandlerResult();
        }

        // Safety guard: do not advance the run while there are still
        // unresolved tool calls in flight.  An AdvanceRun dispatched
        // before all pending tool results are collected would assemble
        // incomplete prompt history (assistant with tool_calls followed
        // by user/assistant instead of tool results) and cause a provider
        // rejection or orphaned tool blocks.
        //
        // pendingToolCalls is array<string, bool> where false = not yet
        // completed, true = completed.  Any value not true means there
        // is unresolved tool work that must complete first.
        $hasUnresolvedToolCalls = false;
        foreach ($state->pendingToolCalls as $completed) {
            if (true !== $completed) {
                $hasUnresolvedToolCalls = true;
                break;
            }
        }
        if ($hasUnresolvedToolCalls) {
            return new HandlerResult();
        }

        // Terminalize cancellation before draining the mailbox so pending
        // model-visible commands (e.g. AppendMessage) are applied only after
        // AgentEnd(cancelled), not in the same event batch.
        if (RunStatus::Cancelling === $state->status) {
            $eventSpecs = [
                [
                    'type' => RunEventTypeEnum::AgentEnd->value,
                    'payload' => [
                        'reason' => 'cancelled',
                        'advance_idempotency_key' => $message->idempotencyKey(),
                    ],
                ],
            ];

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);
            $nextState = $state->with([
                'status' => RunStatus::Cancelled,
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + \count($events),
                'isStreaming' => false,
                'streamingMessage' => null,
                'pendingToolCalls' => [],
                'pendingShellToolCalls' => [],
                'currentOperation' => null,
                'lastAppliedAdvanceKey' => $message->idempotencyKey(),
                'retryableFailure' => false,
                'retryAttempts' => 0,
            ]);

            $postCommit = [];
            if (null !== $this->commandBus) {
                $postCommit[] = AdvanceRunCallbackFactory::create($this->commandBus, $runId, $state->turnNo, 'post-cancel-advance', 'Failed to dispatch AdvanceRun after cancellation terminalized.');
            }

            return new HandlerResult(
                nextState: $nextState,
                events: $events,
                postCommit: $postCommit,
            );
        }

        $mailboxResult = null === $this->tracer
            ? $this->commandMailboxPolicy->applyPendingTurnStartCommands($state)
            : $this->tracer->inSpan('command.application.turn_start_boundary', [
                'run_id' => $runId,
                'turn_no' => $state->turnNo,
                'step_id' => $state->activeStepId,
            ], fn (): CommandApplicationResult => $this->commandMailboxPolicy->applyPendingTurnStartCommands($state))
        ;

        $preparedState = $mailboxResult->state;
        $boundaryEventSpecs = $this->withAdvanceToken($mailboxResult->eventSpecs, $message->idempotencyKey());
        $mailboxEffects = $mailboxResult->effects;

        // When pending commands (steer/follow-up/append_message) added new messages while
        // the run was Completed, Failed, Cancelled, or WaitingHuman, transition to Running
        // and proceed to the next turn — don't bail out early.
        //
        // Compact commands are excluded from this transition: compaction
        // replaces messages in-place and should not advance the run turn.
        // Compact-specific effects are handled by the effects guard below.
        if (\in_array($preparedState->status, [RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::WaitingHuman], true)) {
            // Check for boundary events that are NOT solely from compact
            // (steer/follow-up/append_message/continue produce message-adding events).
            $hasNonCompactBoundaryEvent = false;
            foreach ($boundaryEventSpecs as $spec) {
                $kind = (string) ($spec['payload']['kind'] ?? '');
                if ('compact' !== $kind) {
                    $hasNonCompactBoundaryEvent = true;
                    break;
                }
            }

            if ($hasNonCompactBoundaryEvent && [] !== $boundaryEventSpecs && \in_array($preparedState->status, [RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::WaitingHuman], true)) {
                // The retry counter is deliberately preserved across the
                // boundary drain: an in-flight auto-retry episode must keep
                // counting so retriesExhausted is not delayed.
                $preparedState = $preparedState->with([
                    'status' => RunStatus::Running,
                    'errorMessage' => null,
                    'retryableFailure' => false,
                ]);
            // Fall through to the turn-advance code below.
            } else {
                if ([] === $boundaryEventSpecs) {
                    return new HandlerResult();
                }

                $events = $this->eventFactory->eventsFromSpecs($runId, $preparedState->turnNo, $state->lastSeq + 1, $boundaryEventSpecs);
                $nextState = $preparedState->with([
                    'version' => $state->version + 1,
                    'lastSeq' => $state->lastSeq + \count($events),
                    'lastAppliedAdvanceKey' => $message->idempotencyKey(),
                ]);

                return new HandlerResult(
                    nextState: $nextState,
                    events: $events,
                    effects: $mailboxEffects,
                );
            }
        }

        // If the mailbox drained a compact command, do NOT advance the turn.
        // Compaction replaces RunState.messages and the CompactRunHandler
        // will emit its own events.  We still commit the AgentCommandApplied
        // events from the mailbox drain, and pass the CompactRun effect
        // through for postCommit dispatch.
        if ([] !== $mailboxEffects) {
            $events = $this->eventFactory->eventsFromSpecs($runId, $preparedState->turnNo, $state->lastSeq + 1, $boundaryEventSpecs);
            $nextState = $preparedState->with([
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + \count($events),
                'lastAppliedAdvanceKey' => $message->idempotencyKey(),
            ]);

            return new HandlerResult(
                nextState: $nextState,
                events: $events,
                effects: $mailboxEffects,
            );
        }

        // Compaction guard: while a compaction is active, do NOT advance
        // the turn.  The CompactionStepResultHandler will dispatch
        // AdvanceRun when the async worker completes, at which point the
        // status will be Running (not Compacting) and turn advancement
        // proceeds normally.  Advancing here would emit turn_advanced and
        // history_position_set mid-compaction, confusing the event log.
        if (RunStatus::Compacting === $preparedState->status) {
            if ([] === $boundaryEventSpecs) {
                return new HandlerResult();
            }

            $events = $this->eventFactory->eventsFromSpecs($runId, $preparedState->turnNo, $state->lastSeq + 1, $boundaryEventSpecs);

            return new HandlerResult(
                nextState: $preparedState->with([
                    'version' => $state->version + 1,
                    'lastSeq' => $state->lastSeq + \count($events),
                    'lastAppliedAdvanceKey' => $message->idempotencyKey(),
                ]),
                events: $events,
            );
        }

        // RunMessageProcessor serializes turn advancement under the run lock.
        // RunState.lastSeq is rebuilt from the global canonical event high-water,
        // so discarded-history turn numbers cannot collide with this next turn.
        $nextTurnNo = max($state->lastSeq, $preparedState->turnNo) + 1;
        $nextStepId = $message->stepId();

        // Pre-LLM compaction guard: when the coding-agent-side policy
        // determines auto-compaction should run before the next LLM call,
        // emit a CompactRun effect instead of ExecuteLlmStep.  This keeps
        // the run from advancing until compaction completes (the next
        // AdvanceRun after compaction will proceed normally).
        //
        // GUARD: do NOT fire the pre-LLM guard on post-tool continuations.
        // When the AdvanceRun is triggered by the postCommit callback after
        // tool_batch_committed, we are still inside the assistant/tool cycle
        // and the next LLM step is the final assistant answer.  Compaction
        // here (even with continueAfterCompaction=true) risks:
        //  - empty_summary failure → dead-end with status=Running, no pending
        //    turn, cancel rejected
        //  - summary overhead larger than compacted body → zero token reduction
        //    (user sees “13k → 13k”), model confused by compacted context
        //  - ghost continuation (fixed by continueAfterCompaction flag) but
        //    user policy is to wait for the full assistant/tool cycle to
        //    complete before compacting
        //
        // Detection: walk backward through messages.  If the most recent
        // assistant message has tool_calls and a tool message follows it,
        // this is a post-tool continuation — skip the pre-LLM guard.
        $isPostToolContinuation = false;
        if (null !== $this->preLlmCompactionGuard) {
            $msgCount = \count($preparedState->messages);
            $lastAssistantIdx = null;
            for ($i = $msgCount - 1; $i >= 0; --$i) {
                if ('assistant' === $preparedState->messages[$i]->role) {
                    $lastAssistantIdx = $i;
                    break;
                }
            }

            if (null !== $lastAssistantIdx) {
                $lastAssistant = $preparedState->messages[$lastAssistantIdx];
                $hasToolCalls = \count($lastAssistant->metadata['tool_calls'] ?? []) > 0;

                // Check if a tool message follows this assistant message.
                $hasToolAfter = false;
                for ($i = $lastAssistantIdx + 1; $i < $msgCount; ++$i) {
                    if ('tool' === $preparedState->messages[$i]->role) {
                        $hasToolAfter = true;
                        break;
                    }
                }

                $isPostToolContinuation = $hasToolCalls && $hasToolAfter;
            }
        }

        if (null !== $this->preLlmCompactionGuard && !$isPostToolContinuation) {
            $shouldCompact = null === $this->tracer
                ? $this->preLlmCompactionGuard->shouldCompactBeforeLlmStep(
                    $runId,
                    $nextTurnNo,
                    $preparedState->messages,
                    $preparedState->activeStepId,
                    $preparedState->model,
                )
                : $this->tracer->inSpan('compaction.pre_llm_guard', [
                    'run_id' => $runId,
                    'turn_no' => $nextTurnNo,
                ], fn (): bool => $this->preLlmCompactionGuard->shouldCompactBeforeLlmStep(
                    $runId,
                    $nextTurnNo,
                    $preparedState->messages,
                    $preparedState->activeStepId,
                    $preparedState->model,
                ));

            if ($shouldCompact) {
                $compactStepId = \sprintf('compact-%d', hrtime(true));

                // Pre-LLM guard: this compaction is holding a pending LLM turn.
                // After successful compaction, the conversation must continue
                // (AdvanceRun effect) so the LLM turn can proceed on the
                // compacted context.
                // CompactRun validates against the state turn at handling time.
                // This request holds the next LLM turn but does not advance the
                // state turn until that LLM execution is actually scheduled.
                $compactRequestTurnNo = $preparedState->turnNo;
                $compactEffect = new CompactRun(
                    runId: $runId,
                    turnNo: $compactRequestTurnNo,
                    stepId: $compactStepId,
                    attempt: 1,
                    idempotencyKey: hash('sha256', \sprintf('%s|compact|%d|%s', $runId, $compactRequestTurnNo, $compactStepId)),
                    trigger: 'auto',
                    continueAfterCompaction: true,
                );

                // Persist the request separately from actual compaction start:
                // the CompactRun effect can be lost after commit, while replay
                // must still reject this already-applied AdvanceRun before it
                // drains a newer mailbox command.
                $compactionRequestSpecs = [
                    ...$boundaryEventSpecs,
                    [
                        'type' => RunEventTypeEnum::ContextCompactionRequested->value,
                        'payload' => [
                            'step_id' => $compactStepId,
                            'turn_no' => $nextTurnNo,
                            'request_idempotency_key' => $compactEffect->idempotencyKey(),
                            'advance_idempotency_key' => $message->idempotencyKey(),
                            'trigger' => 'auto',
                        ],
                    ],
                ];
                $events = $this->eventFactory->eventsFromSpecs(
                    $runId,
                    $preparedState->turnNo,
                    $state->lastSeq + 1,
                    $compactionRequestSpecs,
                );

                $compactedState = $preparedState->with([
                    'version' => $state->version + 1,
                    'lastSeq' => $state->lastSeq + \count($events),
                    'lastAppliedAdvanceKey' => $message->idempotencyKey(),
                ]);

                return new HandlerResult(
                    nextState: $compactedState,
                    events: $events,
                    effects: [$compactEffect],
                );
            }
        }

        $effect = new ExecuteLlmStep(
            runId: $runId,
            turnNo: $nextTurnNo,
            stepId: $nextStepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|llm|%d|%s', $runId, $nextTurnNo, $nextStepId)),
            contextRef: \sprintf('hot:run:%s', $runId),
            toolsRef: \sprintf('toolset:run:%s:turn:%d', $runId, $nextTurnNo),
            messages: $preparedState->messages,
        );

        $previousTurnNo = $preparedState->turnNo > 0 ? $preparedState->turnNo : null;

        $eventSpecs = [
            ...$boundaryEventSpecs,
            [
                'type' => RunEventTypeEnum::TurnAdvanced->value,
                'turn_no' => $nextTurnNo,
                'payload' => [
                    'step_id' => $nextStepId,
                    'turn_no' => $nextTurnNo,
                    'operation_attempt' => 1,
                    'operation_idempotency_key' => $effect->idempotencyKey(),
                    'advance_idempotency_key' => $message->idempotencyKey(),
                ],
            ],
            [
                'type' => RunEventTypeEnum::HistoryPositionSet->value,
                'turn_no' => $nextTurnNo,
                'payload' => [
                    'position_turn_no' => $nextTurnNo,
                    'previous_position_turn_no' => $previousTurnNo,
                    'reason' => 'continue',
                ],
            ],
        ];

        $events = $this->eventFactory->eventsFromSpecs($runId, $preparedState->turnNo, $state->lastSeq + 1, $eventSpecs);

        // The retry counter is deliberately preserved across the turn advance:
        // the auto-retry cycle (continue -> advance -> llm step) must keep
        // counting so retriesExhausted is reached at the configured maximum.
        $nextState = $preparedState->with([
            'status' => RunStatus::Running,
            'version' => $state->version + 1,
            'turnNo' => $nextTurnNo,
            'lastSeq' => $state->lastSeq + \count($events),
            'isStreaming' => false,
            'streamingMessage' => null,
            'activeStepId' => $nextStepId,
            'currentOperation' => new CurrentOperationDTO(
                $nextTurnNo,
                $nextStepId,
                1,
                $effect->idempotencyKey(),
            ),
            'lastAppliedAdvanceKey' => $message->idempotencyKey(),
            'retryableFailure' => false,
        ]);

        $postCommit = [];
        if (null !== $this->metrics) {
            $postCommit[] = function () use ($runId, $nextTurnNo): void {
                $this->metrics->recordTurnStarted($runId, $nextTurnNo);
            };
        }

        return new HandlerResult(
            nextState: $nextState,
            events: $events,
            effects: [$effect],
            postCommit: $postCommit,
        );
    }

    /**
     * @param list<array{type: string, payload: array<string, mixed>, turn_no?: int}> $eventSpecs
     *
     * @return list<array{type: string, payload: array<string, mixed>, turn_no?: int}>
     */
    private function withAdvanceToken(array $eventSpecs, string $idempotencyKey): array
    {
        return array_map(
            static fn (array $eventSpec): array => [
                ...$eventSpec,
                'payload' => [...$eventSpec['payload'], 'advance_idempotency_key' => $idempotencyKey],
            ],
            $eventSpecs,
        );
    }
}
