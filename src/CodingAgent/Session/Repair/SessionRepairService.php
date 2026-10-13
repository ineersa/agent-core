<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Repair;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\MalformedToolCallSequenceException;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Runtime\Contract\RepairResult;
use Ineersa\CodingAgent\Runtime\Contract\SessionRepairRefusalReasonEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class SessionRepairService implements SessionRepairServiceInterface
{
    private const string SYNTHETIC_CANCEL_MESSAGE = 'Tool execution cancelled by user.';

    private const string SYNTHETIC_FAILED_TOOL_MESSAGE = 'Tool result unavailable: the run failed before the result was committed.';

    private ToolExecutionEndPayloadCodec $toolExecutionEndPayloadCodec;

    public function __construct(
        private EventStoreInterface $eventStore,
        private ActiveRunContextInterface $activeRunContext,
        private RunStateReducer $runStateReducer,
        private ReplayEventPreparer $replayEventPreparer,
        private EventFactory $eventFactory,
        private AgentMessageToolCallSequenceValidator $toolCallSequenceValidator,
        private RunLockManager $lockManager,
        private LoggerInterface $logger,
        private ToolBatchStoreInterface $toolBatchStore,
        private NormalizerInterface&DenormalizerInterface $serializer,
        private RunCommit $runCommit,
        private \Ineersa\CodingAgent\Session\History\HistoryReplayFilter $historyReplayFilter,
        private DeferredSubagentBatchRepository $deferredBatches,
    ) {
        $this->toolExecutionEndPayloadCodec = new ToolExecutionEndPayloadCodec($this->serializer);
    }

    /** @param list<object> $postCommitActions */
    public function repair(string $runId, bool $apply, string $commandId, array $postCommitActions = []): RepairResult
    {
        return $this->lockManager->synchronized($runId, function () use ($runId, $apply, $commandId, $postCommitActions): RepairResult {
            $this->runCommit->assertTransitionReady($runId);
            $decision = $this->doRepair($runId, $apply, $commandId, leadingActions: $postCommitActions);
            if ($decision instanceof SessionRepairPlan) {
                if ($apply) {
                    $this->runCommit->commit(
                        $decision->previousState,
                        $decision->nextState,
                        $decision->events,
                        dispatchAfterTurnHooks: false,
                        postCommitEffects: $decision->effects,
                        postCommitActions: $decision->actions,
                    );
                    if ([] !== $decision->events) {
                        $this->logger->info('session_repair.completed', [
                            'run_id' => $runId,
                            'component' => 'session.repair',
                            'event_type' => 'session.repair.completed',
                            'terminal_events_appended' => \count($decision->events),
                        ]);
                    }
                }

                return $decision->result;
            }
            // Refused repairs must not execute captured mutation actions such as
            // deferred-child cancellation. Coordination-only commits require an
            // admitted decision and captured actions; no source marker is stored.
            if ($apply && null === $decision->refusalReason && [] !== $postCommitActions) {
                $state = $this->activeRunContext->requireLoaded($runId);
                $this->runCommit->commit($state, $state, [], dispatchAfterTurnHooks: false, postCommitActions: $postCommitActions);
            }

            return $decision;
        });
    }

    public function integrityRefusal(string $runId): ?RepairResult
    {
        return $this->lockManager->synchronized($runId, function () use ($runId): ?RepairResult {
            $history = $this->canonicalHistory($runId);

            return $history instanceof RepairResult ? $history : null;
        });
    }

    /** @return list<RunEvent>|RepairResult */
    private function canonicalHistory(string $runId): array|RepairResult
    {
        $events = $this->eventStore->allFor($runId);
        if ([] === $events) {
            return $this->refusalResult(
                runId: $runId,
                message: 'No canonical events found for session repair.',
                reason: SessionRepairRefusalReasonEnum::NoEvents,
            );
        }

        $sorted = $this->replayEventPreparer->sortBySequence($events);
        $duplicateSeqs = $this->replayEventPreparer->duplicateSequences($sorted);
        if ([] !== $duplicateSeqs) {
            $this->logRefusal($runId, SessionRepairRefusalReasonEnum::DuplicateSequences, ['duplicate_count' => \count($duplicateSeqs)]);

            return new RepairResult(
                repairableStaleCancellationDetected: false,
                staleCancellationRepaired: false,
                message: 'Session repair refused: duplicate event sequences detected.',
                refusalReason: SessionRepairRefusalReasonEnum::DuplicateSequences,
            );
        }

        return $sorted;
    }

    /** @param list<RunEvent> $events */
    private function retainedReplay(string $runId, array $events): RunState
    {
        return $this->runStateReducer->replay(RunState::queued($runId), $this->historyReplayFilter->filter($events))->with([
            'lastSeq' => $this->replayEventPreparer->maxSequence($events),
        ]);
    }

    /**
     * @param list<object> $leadingActions
     */
    private function doRepair(string $runId, bool $apply, string $commandId, array $leadingActions = []): RepairResult|SessionRepairPlan
    {
        $sorted = $this->canonicalHistory($runId);
        if ($sorted instanceof RepairResult) {
            return $sorted;
        }

        $storedState = $this->activeRunContext->requireLoaded($runId);

        if ($storedState->isStreaming) {
            $this->logRefusal($runId, SessionRepairRefusalReasonEnum::ActiveStreaming);

            return new RepairResult(
                repairableStaleCancellationDetected: false,
                staleCancellationRepaired: false,
                message: 'Session repair refused: active streaming detected.',
                refusalReason: SessionRepairRefusalReasonEnum::ActiveStreaming,
            );
        }

        $replayed = $this->retainedReplay($runId, $sorted);

        if ($this->hasTerminalAgentEnd($sorted)) {
            if ($this->terminalReasonIs($sorted, $replayed, RunStatus::Failed, 'failed')) {
                return $this->repairTerminalFailedMalformedBatch(
                    runId: $runId,
                    apply: $apply,
                    sorted: $sorted,
                    replayed: $replayed,
                    storedState: $storedState,
                    leadingActions: $leadingActions,
                );
            }

            return $this->repairTerminalCancelledMalformedBatch(
                runId: $runId,
                apply: $apply,
                sorted: $sorted,
                replayed: $replayed,
                storedState: $storedState,
                leadingActions: $leadingActions,
            );
        }

        if (RunStatus::Cancelling !== $replayed->status) {
            $redrive = $this->currentOperationRedrive($runId, $apply, $sorted, $replayed, $leadingActions);
            if (null !== $redrive) {
                return $redrive;
            }

            if ($this->hasUnresolvedPendingWork($replayed) && !$this->hasOnlyDeferredChildWork($replayed)) {
                return $this->ambiguousRefusal($runId);
            }

            return $this->noRepairResult('No repairable corruption detected.');
        }

        if (!$this->hasCancellationContext($sorted)) {
            return $this->ambiguousRefusal($runId);
        }

        if (!$apply) {
            return new RepairResult(
                repairableStaleCancellationDetected: true,
                staleCancellationRepaired: false,
                message: 'Stale non-terminal cancellation detected; repair available.',
            );
        }

        $maxSeq = $this->replayEventPreparer->maxSequence($sorted);
        $turnNo = $replayed->turnNo;
        $stepId = $replayed->activeStepId ?? \sprintf('repair-cancel-%d', hrtime(true));
        $eventSpecs = [];

        if ($this->llmStepRemainedIncomplete($sorted, $replayed)) {
            $eventSpecs[] = [
                'type' => RunEventTypeEnum::LlmStepAborted->value,
                'payload' => [
                    'step_id' => $stepId,
                    'stop_reason' => 'cancelled',
                    'usage' => null,
                    'aborted_assistant' => null,
                ],
            ];
        }

        $unresolvedIds = $this->unresolvedPendingToolCallIds($replayed);
        $resolvedCount = 0;
        $toolInfo = $this->toolCallInfoFromEvents($sorted);
        foreach ($unresolvedIds as $toolCallId) {
            if ($this->hasDurableToolEnd($sorted, $toolCallId)) {
                continue;
            }

            $info = $toolInfo[$toolCallId] ?? [];
            $toolName = \is_string($info['name'] ?? null) ? $info['name'] : 'unknown';
            $orderIndex = \is_int($info['order_index'] ?? null) ? $info['order_index'] : 0;

            $this->appendSyntheticCancelledToolResultEvents(
                eventSpecs: $eventSpecs,
                runId: $runId,
                turnNo: $turnNo,
                stepId: $stepId,
                toolCallId: $toolCallId,
                toolName: $toolName,
                orderIndex: $orderIndex,
            );
            ++$resolvedCount;
        }

        if ($resolvedCount > 0) {
            $eventSpecs[] = [
                'type' => RunEventTypeEnum::ToolBatchCommitted->value,
                'payload' => [
                    'count' => $resolvedCount,
                    'turn_no' => $turnNo,
                    'step_id' => $stepId,
                ],
            ];
        }

        $eventSpecs[] = [
            'type' => RunEventTypeEnum::AgentEnd->value,
            'payload' => [
                'reason' => 'cancelled',
            ],
        ];

        return $this->appendProposedRepairEvents(
            runId: $runId,
            turnNo: $turnNo,
            maxSeq: $maxSeq,
            eventSpecs: $eventSpecs,
            sorted: $sorted,
            storedState: $storedState,
            successMessage: 'Stale non-terminal cancellation repaired.',
            requiredStatus: RunStatus::Cancelled,
            leadingActions: $leadingActions,
        );
    }

    /**
     * Terminal cancelled sessions can still be malformed: incomplete-batch cancellation
     * used to emit agent_end(cancelled) while some assistant tool_calls only had durable
     * result_received/execution_end and never received a tool message projection. Later
     * follow-ups then fail MalformedToolCallSequenceException. Append missing tool
     * messages only — never a second agent_end or tool_batch_committed.
     *
     * @param list<RunEvent> $sorted
     * @param list<object>   $leadingActions
     */
    private function repairTerminalCancelledMalformedBatch(
        string $runId,
        bool $apply,
        array $sorted,
        RunState $replayed,
        RunState $storedState,
        array $leadingActions,
    ): RepairResult|SessionRepairPlan {
        if (!$this->hasCancellationContext($sorted)) {
            return $this->noRepairResult('No repairable corruption detected.');
        }

        if (!$this->terminalReasonIs($sorted, $replayed, RunStatus::Cancelled, 'cancelled')) {
            return $this->noRepairResult('No repairable corruption detected.');
        }

        return $this->repairTerminalMalformedBatch(
            runId: $runId,
            apply: $apply,
            sorted: $sorted,
            replayed: $replayed,
            storedState: $storedState,
            dryRunMessage: 'Terminal cancelled session has unmatched assistant tool calls; repair available.',
            successMessage: 'Terminal cancelled session repaired: missing tool messages appended.',
            requiredStatus: RunStatus::Cancelled,
            stepIdPrefix: 'repair-cancel',
            leadingActions: $leadingActions,
        );
    }

    /**
     * A failed result handler can persist agent_end(failed) after tool execution
     * without committing the result into message history. Append an explicit
     * unavailable result so a resumed session has a valid assistant/tool pair.
     *
     * @param list<RunEvent> $sorted
     * @param list<object>   $leadingActions
     */
    private function repairTerminalFailedMalformedBatch(
        string $runId,
        bool $apply,
        array $sorted,
        RunState $replayed,
        RunState $storedState,
        array $leadingActions,
    ): RepairResult|SessionRepairPlan {
        return $this->repairTerminalMalformedBatch(
            runId: $runId,
            apply: $apply,
            sorted: $sorted,
            replayed: $replayed,
            storedState: $storedState,
            dryRunMessage: 'Terminal failed session has unmatched assistant tool calls; repair available.',
            successMessage: 'Failed session repaired: missing tool messages appended.',
            requiredStatus: RunStatus::Failed,
            stepIdPrefix: 'repair-failed',
            leadingActions: $leadingActions,
        );
    }

    /**
     * @param list<RunEvent> $sorted
     * @param list<object>   $leadingActions
     */
    private function repairTerminalMalformedBatch(
        string $runId,
        bool $apply,
        array $sorted,
        RunState $replayed,
        RunState $storedState,
        string $dryRunMessage,
        string $successMessage,
        RunStatus $requiredStatus,
        string $stepIdPrefix,
        array $leadingActions,
    ): RepairResult|SessionRepairPlan {
        $missingIds = $this->missingToolResultIds($replayed->messages);
        if (null === $missingIds) {
            return $this->noRepairResult('No repairable corruption detected: append-only repair cannot reorder events for unclosed tool-call batches.');
        }
        if ([] === $missingIds) {
            return $this->noRepairResult('No repairable corruption detected.');
        }

        if (!$apply) {
            return new RepairResult(
                repairableStaleCancellationDetected: true,
                staleCancellationRepaired: false,
                message: $dryRunMessage,
            );
        }

        $maxSeq = $this->replayEventPreparer->maxSequence($sorted);
        $turnNo = $replayed->turnNo;
        $stepId = $replayed->activeStepId ?? \sprintf('%s-%d', $stepIdPrefix, hrtime(true));
        $toolInfo = $this->toolCallInfoFromEvents($sorted);
        $eventSpecs = [];

        usort($missingIds, static function (string $a, string $b) use ($toolInfo): int {
            $orderA = $toolInfo[$a]['order_index'] ?? 0;
            $orderB = $toolInfo[$b]['order_index'] ?? 0;

            return $orderA <=> $orderB;
        });

        foreach ($missingIds as $toolCallId) {
            if ($this->hasDurableToolEnd($sorted, $toolCallId)) {
                continue;
            }

            $info = $toolInfo[$toolCallId] ?? [];
            $toolName = \is_string($info['name'] ?? null) ? $info['name'] : 'unknown';
            $orderIndex = \is_int($info['order_index'] ?? null) ? $info['order_index'] : 0;
            if (RunStatus::Failed === $requiredStatus) {
                $this->appendSyntheticFailedToolResultEvents(
                    eventSpecs: $eventSpecs,
                    runId: $runId,
                    turnNo: $turnNo,
                    stepId: $stepId,
                    toolCallId: $toolCallId,
                    toolName: $toolName,
                    orderIndex: $orderIndex,
                );
            } else {
                $this->appendSyntheticCancelledToolResultEvents(
                    eventSpecs: $eventSpecs,
                    runId: $runId,
                    turnNo: $turnNo,
                    stepId: $stepId,
                    toolCallId: $toolCallId,
                    toolName: $toolName,
                    orderIndex: $orderIndex,
                );
            }
        }

        // ToolBatchCommitted flushes every durable end for the incomplete batch,
        // including ends that predate repair.
        $eventSpecs[] = [
            'type' => RunEventTypeEnum::ToolBatchCommitted->value,
            'payload' => [
                'count' => \count($missingIds),
                'turn_no' => $turnNo,
                'step_id' => $stepId,
            ],
        ];

        return $this->appendProposedRepairEvents(
            runId: $runId,
            turnNo: $turnNo,
            maxSeq: $maxSeq,
            eventSpecs: $eventSpecs,
            sorted: $sorted,
            storedState: $storedState,
            successMessage: $successMessage,
            requiredStatus: $requiredStatus,
            leadingActions: $leadingActions,
            requireValidToolCallSequence: true,
        );
    }

    /**
     * @param list<array{type: string, payload: array<string, mixed>}> $eventSpecs
     * @param list<RunEvent>                                           $sorted
     * @param list<object>                                             $leadingActions
     */
    private function appendProposedRepairEvents(
        string $runId,
        int $turnNo,
        int $maxSeq,
        array $eventSpecs,
        array $sorted,
        RunState $storedState,
        string $successMessage,
        RunStatus $requiredStatus,
        bool $requireValidToolCallSequence = false,
        array $leadingActions = [],
    ): RepairResult|SessionRepairPlan {
        $proposedEvents = $this->eventFactory->eventsFromSpecs($runId, $turnNo, $maxSeq + 1, $eventSpecs);
        $hypothetical = array_merge($sorted, $proposedEvents);
        $hypotheticalReplay = $this->retainedReplay($runId, $hypothetical);

        if ($requiredStatus !== $hypotheticalReplay->status) {
            $this->logger->warning('session_repair.refused', [
                'run_id' => $runId,
                'component' => 'session.repair',
                'event_type' => 'session.repair.refused',
                'refusal_reason' => SessionRepairRefusalReasonEnum::ReplayValidationFailed->value,
                'final_status' => $hypotheticalReplay->status->value,
                'required_status' => $requiredStatus->value,
            ]);

            return new RepairResult(
                repairableStaleCancellationDetected: false,
                staleCancellationRepaired: false,
                message: \sprintf('Session repair refused: hypothetical replay did not preserve %s.', $requiredStatus->value),
                refusalReason: SessionRepairRefusalReasonEnum::ReplayValidationFailed,
            );
        }

        if ($requireValidToolCallSequence) {
            try {
                $this->toolCallSequenceValidator->validate($hypotheticalReplay->messages);
            } catch (MalformedToolCallSequenceException $exception) {
                $this->logger->warning('session_repair.refused', [
                    'run_id' => $runId,
                    'component' => 'session.repair',
                    'event_type' => 'session.repair.refused',
                    'refusal_reason' => SessionRepairRefusalReasonEnum::ReplayValidationFailed->value,
                    'validator_error' => $exception->getMessage(),
                ]);

                return new RepairResult(
                    repairableStaleCancellationDetected: false,
                    staleCancellationRepaired: false,
                    message: 'Session repair refused: repaired message sequence remains malformed.',
                    refusalReason: SessionRepairRefusalReasonEnum::ReplayValidationFailed,
                );
            }
        }

        $persisted = $hypotheticalReplay->with([
            'version' => $storedState->version,
            'isStreaming' => false,
            'streamingMessage' => null,
        ]);

        return new SessionRepairPlan(
            $storedState,
            $persisted,
            $proposedEvents,
            $leadingActions,
            new RepairResult(
                repairableStaleCancellationDetected: true,
                staleCancellationRepaired: true,
                message: $successMessage,
            ),
        );
    }

    /**
     * @param list<RunEvent> $sorted
     */
    private function terminalReasonIs(array $sorted, RunState $replayed, RunStatus $status, string $reason): bool
    {
        $sorted = $this->historyReplayFilter->filter($sorted);
        if ($status === $replayed->status) {
            return true;
        }

        foreach (array_reverse($sorted) as $event) {
            if (RunEventTypeEnum::AgentEnd->value !== $event->type) {
                continue;
            }

            return $reason === ($event->payload['reason'] ?? null);
        }

        return false;
    }

    /**
     * Detect missing_tool_results via the shared validator so repair detection cannot drift.
     *
     * @param list<AgentMessage> $messages
     *
     * @return list<string>|null Missing tool_call ids, empty list when clean, null when another
     *                           violation type (e.g. unclosed_batch) makes append-only repair unsafe
     */
    private function missingToolResultIds(array $messages): ?array
    {
        try {
            $this->toolCallSequenceValidator->validate($messages);

            return [];
        } catch (MalformedToolCallSequenceException $exception) {
            // Append-only repair cannot reorder events, so unclosed-batch shapes are not repairable.
            return 'missing_tool_results' === $exception->violationType
                ? $exception->expectedIds
                : null;
        }
    }

    /**
     * @param list<array{type: string, payload: array<string, mixed>}> $eventSpecs
     */
    private function appendSyntheticCancelledToolResultEvents(
        array &$eventSpecs,
        string $runId,
        int $turnNo,
        string $stepId,
        string $toolCallId,
        string $toolName,
        int $orderIndex,
    ): void {
        $this->appendSyntheticToolErrorResultEvents(
            eventSpecs: $eventSpecs,
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            toolCallId: $toolCallId,
            toolName: $toolName,
            orderIndex: $orderIndex,
            idempotencyPurpose: 'repair-cancel',
            errorType: 'cancelled',
            message: self::SYNTHETIC_CANCEL_MESSAGE,
        );
    }

    /**
     * @param list<array{type: string, payload: array<string, mixed>}> $eventSpecs
     */
    private function appendSyntheticFailedToolResultEvents(
        array &$eventSpecs,
        string $runId,
        int $turnNo,
        string $stepId,
        string $toolCallId,
        string $toolName,
        int $orderIndex,
    ): void {
        $this->appendSyntheticToolErrorResultEvents(
            eventSpecs: $eventSpecs,
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            toolCallId: $toolCallId,
            toolName: $toolName,
            orderIndex: $orderIndex,
            idempotencyPurpose: 'repair-failed',
            errorType: 'result_not_committed',
            message: self::SYNTHETIC_FAILED_TOOL_MESSAGE,
        );
    }

    /**
     * @param list<array{type: string, payload: array<string, mixed>}> $eventSpecs
     */
    private function appendSyntheticToolErrorResultEvents(
        array &$eventSpecs,
        string $runId,
        int $turnNo,
        string $stepId,
        string $toolCallId,
        string $toolName,
        int $orderIndex,
        string $idempotencyPurpose,
        string $errorType,
        string $message,
    ): void {
        $syntheticResult = new ToolCallResult(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s-%s-%s', $idempotencyPurpose, $runId, $toolCallId)),
            toolCallId: $toolCallId,
            orderIndex: $orderIndex,
            result: [
                'tool_name' => $toolName,
                'content' => [['type' => 'text', 'text' => $message]],
            ],
            isError: true,
            error: [
                'type' => $errorType,
                'message' => $message,
            ],
        );

        $eventSpecs[] = [
            'type' => RunEventTypeEnum::ToolExecutionEnd->value,
            'payload' => $this->toolExecutionEndPayloadCodec->toEventPayload($syntheticResult),
        ];
    }

    /**
     * @param list<RunEvent> $events
     */
    private function hasTerminalAgentEnd(array $events): bool
    {
        $events = $this->historyReplayFilter->filter($events);
        foreach (array_reverse($events) as $event) {
            if (RunEventTypeEnum::TurnAdvanced->value === $event->type) {
                // A later turn supersedes an earlier terminal lifecycle (for
                // example a terminal standalone shell child turn).
                return false;
            }
            if (RunEventTypeEnum::AgentEnd->value === $event->type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<RunEvent> $events
     */
    private function hasCancellationContext(array $events): bool
    {
        $events = $this->historyReplayFilter->filter($events);
        foreach ($events as $event) {
            if (RunEventTypeEnum::AgentCommandApplied->value !== $event->type) {
                continue;
            }

            $kind = \is_string($event->payload['kind'] ?? null) ? $event->payload['kind'] : null;
            if ('cancel' === $kind) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only an active LLM operation needs an abort during cancellation. Retained
     * operation state distinguishes it from direct shell and compaction work;
     * completed/failed/aborted LLM events clear the operation during replay.
     *
     * @param list<RunEvent> $events
     */
    private function llmStepRemainedIncomplete(array $events, RunState $replayed): bool
    {
        $events = $this->historyReplayFilter->filter($events);
        $operation = $replayed->currentOperation;
        if (null === $operation || [] !== $replayed->pendingShellToolCalls) {
            return false;
        }

        foreach (array_reverse($events) as $event) {
            if (RunEventTypeEnum::ContextCompactionStarted->value === $event->type
                && $operation->stepId === ($event->payload['step_id'] ?? null)) {
                return false;
            }
            if (RunEventTypeEnum::TurnAdvanced->value === $event->type
                && $operation->stepId === ($event->payload['step_id'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<RunEvent> $events
     */
    private function hasDurableToolEnd(array $events, string $toolCallId): bool
    {
        $events = $this->historyReplayFilter->filter($events);
        foreach ($events as $event) {
            if (RunEventTypeEnum::ToolExecutionEnd->value !== $event->type) {
                continue;
            }
            if ($this->toolExecutionEndPayloadCodec->fromEventPayload($event->payload)->toolCallId === $toolCallId) {
                return true;
            }
        }

        return false;
    }

    private function hasUnresolvedPendingWork(RunState $state): bool
    {
        foreach ($state->pendingToolCalls as $completed) {
            if (false === $completed) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function unresolvedPendingToolCallIds(RunState $state): array
    {
        $ids = [];
        foreach ($state->pendingToolCalls as $toolCallId => $completed) {
            if (false === $completed) {
                $ids[] = $toolCallId;
            }
        }

        return $ids;
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return array<string, array{name: string, order_index: int}>
     */
    private function toolCallInfoFromEvents(array $events): array
    {
        $events = $this->historyReplayFilter->filter($events);
        $map = [];

        foreach ($events as $event) {
            if (RunEventTypeEnum::ToolExecutionStart->value === $event->type) {
                $id = \is_string($event->payload['tool_call_id'] ?? null) ? $event->payload['tool_call_id'] : null;
                if (null === $id) {
                    continue;
                }
                $name = \is_string($event->payload['tool_name'] ?? null) ? $event->payload['tool_name'] : ($map[$id]['name'] ?? 'unknown');
                $orderIndex = \is_int($event->payload['order_index'] ?? null) ? $event->payload['order_index'] : ($map[$id]['order_index'] ?? 0);
                $map[$id] = ['name' => $name, 'order_index' => $orderIndex];

                continue;
            }

            if (RunEventTypeEnum::LlmStepCompleted->value !== $event->type) {
                continue;
            }

            $assistant = \is_array($event->payload['assistant_message'] ?? null) ? $event->payload['assistant_message'] : [];
            $toolCalls = \is_array($assistant['tool_calls'] ?? null) ? $assistant['tool_calls'] : [];
            foreach ($toolCalls as $localIndex => $toolCall) {
                if (!\is_array($toolCall)) {
                    continue;
                }
                $id = \is_string($toolCall['id'] ?? null) ? $toolCall['id'] : null;
                if (null === $id || isset($map[$id])) {
                    continue;
                }
                $function = \is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
                $name = \is_string($function['name'] ?? null) ? $function['name'] : 'unknown';
                $map[$id] = ['name' => $name, 'order_index' => $localIndex];
            }
        }

        return $map;
    }

    /**
     * A manual /repair explicitly requests the current operation again. It never appends a synthetic completion event: workers and
     * result handlers remain the authoritative completion path.
     *
     * @param list<RunEvent> $events
     * @param list<object>   $leadingActions
     */
    private function currentOperationRedrive(string $runId, bool $apply, array $events, RunState $state, array $leadingActions): RepairResult|SessionRepairPlan|null
    {
        $events = $this->historyReplayFilter->filter($events);
        $effects = [];
        $operation = $state->currentOperation;
        $standaloneShellOperation = false;
        foreach ($state->pendingShellToolCalls as $toolCallId => $_) {
            $shell = $this->shellEffectFromEvents($runId, $toolCallId, $events);
            if (null === $shell) {
                return $this->refusalResult($runId, 'Session repair refused: current shell command cannot be reconstructed.', SessionRepairRefusalReasonEnum::AmbiguousPendingWork);
            }
            $effects[] = $shell;
            $standaloneShellOperation = $standaloneShellOperation || (null !== $operation && $this->isStandaloneShellOperation($operation, $toolCallId, $events));
        }
        if (RunStatus::Compacting === $state->status) {
            $request = null;
            foreach (array_reverse($events) as $event) {
                if (RunEventTypeEnum::ContextCompactionStarted->value !== $event->type || !\is_array($event->payload['worker_request'] ?? null)) {
                    continue;
                }
                $candidate = $this->serializer->denormalize($event->payload['worker_request'], ExecuteCompactionStep::class);
                if ($candidate instanceof ExecuteCompactionStep && $candidate->runId() === $runId && $operation?->matchesMessage($candidate)) {
                    $request = $candidate;
                    break;
                }
            }
            if (null === $request) {
                return $this->refusalResult($runId, 'Session repair refused: current compaction input cannot be reconstructed.', SessionRepairRefusalReasonEnum::AmbiguousPendingWork);
            }
            $effects[] = $request;
        } elseif (null !== $operation && !$standaloneShellOperation) {
            $effects[] = new ExecuteLlmStep($runId, $operation->turnNo, $operation->stepId, $operation->attempt, $operation->idempotencyKey, \sprintf('toolset:run:%s:turn:%d', $runId, $operation->turnNo), $state->messages);
        }
        if (null !== $state->activeStepId && [] !== $state->pendingToolCalls) {
            $batch = $this->toolBatchStore->load($runId, $state->turnNo, $state->activeStepId);
            if (null !== $batch && !$batch->finalized && [] === $batch->awaitingHumanInput) {
                foreach ($batch->inFlight as $id => $_) {
                    if (($state->pendingToolCalls[$id] ?? true) || $this->isDeferredChildCall($state, $id)) {
                        continue;
                    }
                    // Collected siblings are reused, not re-executed. Queued calls
                    // remain behind the collector's capacity and sequential barriers.
                    $effects[] = $batch->results[$id] ?? $batch->calls[$id] ?? throw new \RuntimeException('Stored tool batch call is missing.');
                }
            }
        }
        if ([] === $effects && null === $operation && RunStatus::Running === $state->status && [] === $state->pendingToolCalls && [] === $state->pendingShellToolCalls) {
            $effects[] = new AdvanceRun($runId, $state->turnNo, \sprintf('repair-advance-%d', $state->turnNo), 1, hash('sha256', \sprintf('%s|repair-advance|%d|%d', $runId, $state->turnNo, $state->lastSeq)));
        }
        if ([] === $effects) {
            return null;
        }
        if (!$apply) {
            return new RepairResult(false, false, 'Active operation repair available. External effects may already have occurred.');
        }
        $stored = $this->activeRunContext->requireLoaded($runId);

        // Explicit repair authorizes these ordinary sends. Canonical start events
        // establish identity and input, not whether an external tool already acted.
        return new SessionRepairPlan($stored, $state->with(['version' => $stored->version]), [], $leadingActions, new RepairResult(false, false, 'Active operation redrive requested. Sends run after owner-lock release; failed sends produce a runtime error notification. External effects may already have occurred.', activeOperationsRedriven: \count($effects)), $effects);
    }

    private function hasOnlyDeferredChildWork(RunState $state): bool
    {
        if ([] === $state->pendingToolCalls || [] !== $state->pendingShellToolCalls || [] !== $state->pendingHumanInputRequests || null !== $state->currentOperation) {
            return false;
        }
        $hasDeferredCall = false;
        foreach ($state->pendingToolCalls as $toolCallId => $completed) {
            if ($completed) {
                continue;
            }
            if (!$this->isDeferredChildCall($state, $toolCallId)) {
                return false;
            }
            $hasDeferredCall = true;
        }

        return $hasDeferredCall;
    }

    private function isDeferredChildCall(RunState $state, string $toolCallId): bool
    {
        return $this->deferredBatches->hasLaunchedPendingParentToolCall($state->runId, $state->turnNo, $toolCallId);
    }

    /**
     * @param list<RunEvent> $events
     */
    private function isStandaloneShellOperation(CurrentOperationDTO $operation, string $toolCallId, array $events): bool
    {
        foreach (array_reverse($events) as $event) {
            if (RunEventTypeEnum::AgentCommandApplied->value !== $event->type || 'shell_command' !== ($event->payload['kind'] ?? null)) {
                continue;
            }

            $key = $event->payload['idempotency_key'] ?? null;
            if (!\is_string($key)
                || 'sh_'.hash('sha256', $key) !== $toolCallId
                || true !== ($event->payload['standalone'] ?? null)) {
                continue;
            }

            $shellOperation = $this->shellOperationFromEvent($event);
            if (null === $shellOperation) {
                throw new \UnexpectedValueException('Standalone shell command event is missing a normalized current_operation.');
            }

            return $operation->matches(
                $shellOperation->turnNo,
                $shellOperation->stepId,
                $shellOperation->attempt,
                $shellOperation->idempotencyKey,
            );
        }

        return false;
    }

    /**
     * @param list<RunEvent> $events
     */
    private function shellEffectFromEvents(string $runId, string $toolCallId, array $events): ?ExecuteShellToolCall
    {
        foreach (array_reverse($events) as $event) {
            if (RunEventTypeEnum::AgentCommandApplied->value !== $event->type || 'shell_command' !== ($event->payload['kind'] ?? null)) {
                continue;
            }
            $key = $event->payload['idempotency_key'] ?? null;
            $text = $event->payload['text'] ?? null;
            $standalone = $event->payload['standalone'] ?? null;
            if (!\is_string($key)
                || !\is_string($text)
                || !\is_bool($standalone)
                || 'sh_'.hash('sha256', $key) !== $toolCallId
                || !str_starts_with($text, '!')) {
                continue;
            }

            $operation = $this->shellOperationFromEvent($event);
            if (null === $operation) {
                return null;
            }

            return new ExecuteShellToolCall(
                runId: $runId,
                turnNo: $operation->turnNo,
                stepId: $operation->stepId,
                attempt: $operation->attempt,
                toolCallId: $toolCallId,
                commandText: ltrim(substr($text, 1)),
                standalone: $standalone,
            );
        }

        return null;
    }

    private function shellOperationFromEvent(RunEvent $event): ?CurrentOperationDTO
    {
        $rawOperation = $event->payload['current_operation'] ?? null;
        if (!\is_array($rawOperation)) {
            return null;
        }

        try {
            $operation = $this->serializer->denormalize($rawOperation, CurrentOperationDTO::class);
        } catch (\Throwable $exception) {
            $this->logger->warning('Session repair could not denormalize shell operation.', [
                'component' => 'session.repair',
                'event_type' => 'session.repair.shell_operation_denormalization_failed',
                'run_id' => $event->runId,
                'exception' => $exception::class,
            ]);

            return null;
        }

        return $operation instanceof CurrentOperationDTO ? $operation : null;
    }

    private function ambiguousRefusal(string $runId): RepairResult
    {
        $this->logRefusal($runId, SessionRepairRefusalReasonEnum::AmbiguousPendingWork);

        return new RepairResult(
            repairableStaleCancellationDetected: false,
            staleCancellationRepaired: false,
            message: 'Session repair refused: ambiguous pending work.',
            refusalReason: SessionRepairRefusalReasonEnum::AmbiguousPendingWork,
        );
    }

    private function noRepairResult(string $message): RepairResult
    {
        return new RepairResult(
            repairableStaleCancellationDetected: false,
            staleCancellationRepaired: false,
            message: $message,
        );
    }

    private function refusalResult(string $runId, string $message, SessionRepairRefusalReasonEnum $reason): RepairResult
    {
        $this->logRefusal($runId, $reason);

        return new RepairResult(
            repairableStaleCancellationDetected: false,
            staleCancellationRepaired: false,
            message: $message,
            refusalReason: $reason,
        );
    }

    /**
     * @param array<string, int|string> $extra
     */
    private function logRefusal(string $runId, SessionRepairRefusalReasonEnum $reason, array $extra = []): void
    {
        $this->logger->warning('session_repair.refused', array_merge([
            'run_id' => $runId,
            'component' => 'session.repair',
            'event_type' => 'session.repair.refused',
            'refusal_reason' => $reason->value,
        ], $extra));
    }
}
