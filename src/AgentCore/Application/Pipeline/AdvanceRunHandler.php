<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Contract\Compaction\PreLlmCompactionGuardInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;

final readonly class AdvanceRunHandler implements RunMessageHandler
{
    public function __construct(
        private CommandMailboxPolicy $commandMailboxPolicy,
        private EventFactory $eventFactory,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
        private ?PreLlmCompactionGuardInterface $preLlmCompactionGuard = null,
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

        $mailboxResult = null === $this->tracer
            ? $this->commandMailboxPolicy->applyPendingTurnStartCommands($state)
            : $this->tracer->inSpan('command.application.turn_start_boundary', [
                'run_id' => $runId,
                'turn_no' => $state->turnNo,
                'step_id' => $state->activeStepId,
            ], fn (): CommandApplicationResult => $this->commandMailboxPolicy->applyPendingTurnStartCommands($state))
        ;

        $preparedState = $mailboxResult->state;
        $boundaryEventSpecs = $mailboxResult->eventSpecs;
        $mailboxEffects = $mailboxResult->effects;

        if (RunStatus::Cancelling === $preparedState->status) {
            $eventSpecs = [
                ...$boundaryEventSpecs,
                [
                    'type' => RunEventTypeEnum::AgentEnd->value,
                    'payload' => ['reason' => 'cancelled'],
                ],
            ];

            $events = $this->eventFactory->eventsFromSpecs($runId, $preparedState->turnNo, $state->lastSeq + 1, $eventSpecs);
            $nextState = new RunState(
                runId: $preparedState->runId,
                status: RunStatus::Cancelled,
                version: $state->version + 1,
                turnNo: $preparedState->turnNo,
                lastSeq: $state->lastSeq + \count($events),
                isStreaming: false,
                streamingMessage: null,
                pendingToolCalls: [],
                errorMessage: $preparedState->errorMessage,
                messages: $preparedState->messages,
                activeStepId: $preparedState->activeStepId,
                retryableFailure: false,
            );

            return new HandlerResult(
                nextState: $nextState,
                events: $events,
                effects: $mailboxEffects,
            );
        }

        // When pending commands (steer/follow-up) added new messages while
        // the run was Completed or Failed, transition to Running and proceed
        // to the next turn — don't bail out early.
        //
        // Compact commands are excluded from this transition: compaction
        // replaces messages in-place and should not advance the run turn.
        // Compact-specific effects are handled by the effects guard below.
        if (\in_array($preparedState->status, [RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::WaitingHuman], true)) {
            // Check for boundary events that are NOT solely from compact
            // (steer/follow-up/continue produce message-adding events).
            $hasNonCompactBoundaryEvent = false;
            foreach ($boundaryEventSpecs as $spec) {
                $kind = (string) ($spec['payload']['kind'] ?? '');
                if ('compact' !== $kind) {
                    $hasNonCompactBoundaryEvent = true;
                    break;
                }
            }

            if ($hasNonCompactBoundaryEvent && [] !== $boundaryEventSpecs && \in_array($preparedState->status, [RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled], true)) {
                $preparedState = new RunState(
                    runId: $preparedState->runId,
                    status: RunStatus::Running,
                    version: $preparedState->version,
                    turnNo: $preparedState->turnNo,
                    lastSeq: $preparedState->lastSeq,
                    isStreaming: $preparedState->isStreaming,
                    streamingMessage: $preparedState->streamingMessage,
                    pendingToolCalls: $preparedState->pendingToolCalls,
                    errorMessage: null,
                    messages: $preparedState->messages,
                    activeStepId: $preparedState->activeStepId,
                    retryableFailure: false,
                );
            // Fall through to the turn-advance code below.
            } else {
                if ([] === $boundaryEventSpecs) {
                    return new HandlerResult();
                }

                $events = $this->eventFactory->eventsFromSpecs($runId, $preparedState->turnNo, $state->lastSeq + 1, $boundaryEventSpecs);
                $nextState = new RunState(
                    runId: $preparedState->runId,
                    status: $preparedState->status,
                    version: $state->version + 1,
                    turnNo: $preparedState->turnNo,
                    lastSeq: $state->lastSeq + \count($events),
                    isStreaming: $preparedState->isStreaming,
                    streamingMessage: $preparedState->streamingMessage,
                    pendingToolCalls: $preparedState->pendingToolCalls,
                    errorMessage: $preparedState->errorMessage,
                    messages: $preparedState->messages,
                    activeStepId: $preparedState->activeStepId,
                    retryableFailure: $preparedState->retryableFailure,
                );

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
            $nextState = new RunState(
                runId: $preparedState->runId,
                status: $preparedState->status,
                version: $state->version + 1,
                turnNo: $preparedState->turnNo,
                lastSeq: $state->lastSeq + \count($events),
                isStreaming: $preparedState->isStreaming,
                streamingMessage: $preparedState->streamingMessage,
                pendingToolCalls: $preparedState->pendingToolCalls,
                errorMessage: $preparedState->errorMessage,
                messages: $preparedState->messages,
                activeStepId: $preparedState->activeStepId,
                retryableFailure: $preparedState->retryableFailure,
            );

            return new HandlerResult(
                nextState: $nextState,
                events: $events,
                effects: $mailboxEffects,
            );
        }

        $nextTurnNo = $preparedState->turnNo + 1;
        $nextStepId = $message->stepId();

        // Pre-LLM compaction guard: when the coding-agent-side policy
        // determines auto-compaction should run before the next LLM call,
        // emit a CompactRun effect instead of ExecuteLlmStep.  This keeps
        // the run from advancing until compaction completes (the next
        // AdvanceRun after compaction will proceed normally).
        if (null !== $this->preLlmCompactionGuard) {
            $shouldCompact = null === $this->tracer
                ? $this->preLlmCompactionGuard->shouldCompactBeforeLlmStep(
                    $runId,
                    $nextTurnNo,
                    $preparedState->messages,
                    $preparedState->activeStepId,
                )
                : $this->tracer->inSpan('compaction.pre_llm_guard', [
                    'run_id' => $runId,
                    'turn_no' => $nextTurnNo,
                ], fn (): bool => $this->preLlmCompactionGuard->shouldCompactBeforeLlmStep(
                    $runId,
                    $nextTurnNo,
                    $preparedState->messages,
                    $preparedState->activeStepId,
                ));

            if ($shouldCompact) {
                $compactStepId = \sprintf('compact-%d', hrtime(true));

                $compactEffect = new CompactRun(
                    runId: $runId,
                    turnNo: $nextTurnNo,
                    stepId: $compactStepId,
                    attempt: 1,
                    idempotencyKey: hash('sha256', \sprintf('%s|compact|%d|%s', $runId, $nextTurnNo, $compactStepId)),
                    trigger: 'auto',
                );

                // Emit boundary events only — do NOT emit TurnAdvanced
                // or LeafSet (compaction does not advance the turn).
                $events = $this->eventFactory->eventsFromSpecs(
                    $runId,
                    $preparedState->turnNo,
                    $state->lastSeq + 1,
                    $boundaryEventSpecs,
                );

                $compactedState = new RunState(
                    runId: $preparedState->runId,
                    status: $preparedState->status,
                    version: $state->version + 1,
                    turnNo: $preparedState->turnNo,
                    lastSeq: $state->lastSeq + \count($events),
                    isStreaming: $preparedState->isStreaming,
                    streamingMessage: $preparedState->streamingMessage,
                    pendingToolCalls: $preparedState->pendingToolCalls,
                    errorMessage: $preparedState->errorMessage,
                    messages: $preparedState->messages,
                    activeStepId: $preparedState->activeStepId,
                    retryableFailure: $preparedState->retryableFailure,
                );

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
        );

        $parentTurnNo = $preparedState->turnNo > 0 ? $preparedState->turnNo : null;

        $eventSpecs = [
            ...$boundaryEventSpecs,
            [
                'type' => RunEventTypeEnum::TurnAdvanced->value,
                'turn_no' => $nextTurnNo,
                'payload' => [
                    'step_id' => $nextStepId,
                    'turn_no' => $nextTurnNo,
                    'parent_turn_no' => $parentTurnNo,
                ],
            ],
            [
                'type' => RunEventTypeEnum::LeafSet->value,
                'turn_no' => $nextTurnNo,
                'payload' => [
                    'turn_no' => $nextTurnNo,
                    // In the normal continue path, previous_turn_no and parent_turn_no
                    // intentionally coincide (both equal the turn we are leaving).
                    // Future rewind/branch emitters may diverge them:
                    //   previous_turn_no = the turn being abandoned,
                    //   parent_turn_no   = the common ancestor for the new branch.
                    'previous_turn_no' => $parentTurnNo,
                    'parent_turn_no' => $parentTurnNo,
                    'reason' => 'continue',
                ],
            ],
        ];

        $events = $this->eventFactory->eventsFromSpecs($runId, $preparedState->turnNo, $state->lastSeq + 1, $eventSpecs);

        $nextState = new RunState(
            runId: $preparedState->runId,
            status: RunStatus::Running,
            version: $state->version + 1,
            turnNo: $nextTurnNo,
            lastSeq: $state->lastSeq + \count($events),
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: $preparedState->pendingToolCalls,
            errorMessage: $preparedState->errorMessage,
            messages: $preparedState->messages,
            activeStepId: $nextStepId,
            retryableFailure: false,
        );

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
}
