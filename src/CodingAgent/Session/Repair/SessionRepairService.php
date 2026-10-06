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
        private \Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface $executionOperations,
        private \Ineersa\AgentCore\Application\Handler\ToolExecutionAuthorization $toolAuthorization,
    ) {
        $this->toolExecutionEndPayloadCodec = new ToolExecutionEndPayloadCodec($this->serializer);
    }

    public function repair(string $runId, bool $apply, string $commandId): RepairResult
    {
        return $this->lockManager->synchronized($runId, function () use ($runId, $apply, $commandId): RepairResult {
            $source = \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance::actionIdentity(\Ineersa\CodingAgent\Application\Message\RepairSession::class, $runId, $commandId);
            $this->runCommit->assertTransitionReady($runId);
            if ($apply && $this->runCommit->sourceIdentityAlreadyAccepted($source)) {
                return $this->noRepairResult('Repair delivery was already accepted.');
            }
            $result = $this->doRepair($runId, $apply, $commandId);
            if ($apply && !\in_array($result->refusalReason, [SessionRepairRefusalReasonEnum::NoEvents, SessionRepairRefusalReasonEnum::DuplicateSequences, SessionRepairRefusalReasonEnum::MissingSequences], true)
                && !$this->runCommit->sourceIdentityAlreadyAccepted($source)) {
                $state = $this->activeRunContext->requireLoaded($runId);
                $this->runCommit->commit($state, $state, [], dispatchAfterTurnHooks: false, sourceIdentity: $source);
            }

            return $result;
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

        $missingSeqs = $this->replayEventPreparer->missingSequences($sorted);
        if ([] !== $missingSeqs) {
            $this->logRefusal($runId, SessionRepairRefusalReasonEnum::MissingSequences, ['missing_count' => \count($missingSeqs)]);

            return new RepairResult(
                repairableStaleCancellationDetected: false,
                staleCancellationRepaired: false,
                message: 'Session repair refused: missing event sequences detected.',
                refusalReason: SessionRepairRefusalReasonEnum::MissingSequences,
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

    private function matchesUnknownExecution(RunState $state, \Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown $notice): bool
    {
        if ($state->currentOperation?->matchesMessage($notice)) {
            return true;
        }
        // Attached shells deliberately do not replace the active model token.
        // Match their own pending call, as the normal unknown-notice handler does.
        foreach ($state->currentToolCalls as $call) {
            if ($state->turnNo === $notice->turnNo()
                && $call->batchId === \Ineersa\AgentCore\Domain\Run\ToolBatchIdentity::fromTurnAndStep($notice->turnNo(), $notice->stepId())
                && $call->attempt === $notice->attempt()
                && isset($state->pendingShellToolCalls[$call->toolCallId])
                && hash('sha256', $state->runId.'|'.$call->toolCallId) === $notice->idempotencyKey()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<object> $actions
     * @return array<string, int|string>
     */
    private function repairSource(string $runId, string $commandId, array $actions): array
    {
        $source = \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance::actionIdentity(\Ineersa\CodingAgent\Application\Message\RepairSession::class, $runId, $commandId);
        foreach ($actions as $action) {
            if ($action instanceof \Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO) {
                $source['command_type'] = \Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO::class;
            }
        }

        return $source;
    }

    /** @param list<RunEvent> $leadingEvents
     * @param list<object> $leadingActions
     */
    private function doRepair(string $runId, bool $apply, string $commandId, array $leadingEvents = [], array $leadingActions = []): RepairResult
    {
        $sorted = $this->canonicalHistory($runId);
        if ($sorted instanceof RepairResult) {
            return $sorted;
        }

        // Prepare the remaining repair before publishing retirement. One intent
        // retains both decisions, so recovery never regenerates the second one.
        $maxSeq = $this->replayEventPreparer->maxSequence($sorted);
        foreach ($leadingEvents as $offset => $event) {
            $sorted[] = new RunEvent($event->runId, $maxSeq + $offset + 1, $event->turnNo, $event->type, $event->payload, $event->createdAt);
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

        $unknown = [] === $leadingEvents ? [...$this->executionOperations->unknownExecutionsForRepair($runId), ...$this->toolAuthorization->unknownExecutionsForRepair($runId)] : [];
        if ([] !== $unknown) {
            foreach ($unknown as $notice) {
                ($notice instanceof \Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown ? $this->executionOperations : $this->toolAuthorization)->assertUnknownRepairable($notice);
            }
            $warning = \Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO::WARNING;
            if (!$apply) {
                return new RepairResult(true, false, 'Unknown execution repair available. '.$warning);
            }
            $actions = array_map(static fn ($notice) => new \Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO($notice), $unknown);
            $event = RunEvent::forAppend($runId, $replayed->turnNo, RunEventTypeEnum::ExecutionUnknownRetired->value, ['warning' => $warning, 'execution_ids' => array_map(static fn ($notice) => $notice instanceof \Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown ? $notice->effectId : $notice->authorizationId, $unknown)]);
            $events = [$event];
            $nextState = $storedState;
            foreach ($unknown as $notice) {
                $current = $notice instanceof \Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown
                    ? $this->matchesUnknownExecution($replayed, $notice)
                    : $replayed->turnNo === $notice->turnNo() && $replayed->activeStepId === $notice->stepId() && isset($replayed->pendingToolCalls[$notice->toolCallId]) && $this->toolAuthorization->matchesCurrentInvocation($notice);
                if ($current && !\in_array($replayed->status, [RunStatus::Failed, RunStatus::Completed, RunStatus::Cancelled], true)) {
                    $events[] = RunEvent::forAppend($runId, $replayed->turnNo, RunEventTypeEnum::AgentEnd->value, ['reason' => 'failed', 'error' => $warning, 'error_type' => 'execution_outcome_unknown']);
                    $nextState = $storedState->with(['status' => RunStatus::Failed, 'isStreaming' => false, 'streamingMessage' => null, 'errorMessage' => $warning]);
                    break;
                }
            }
            $result = $this->doRepair($runId, true, $commandId, $events, $actions);
            if (!$this->runCommit->sourceIdentityAlreadyAccepted(\Ineersa\AgentCore\Application\Pipeline\SourceAcceptance::actionIdentity(\Ineersa\CodingAgent\Application\Message\RepairSession::class, $runId, $commandId))) {
                $this->runCommit->commit($storedState, $nextState, $events, dispatchAfterTurnHooks: false, postCommitActions: $actions, sourceIdentity: $this->repairSource($runId, $commandId, $actions));
            }

            return new RepairResult(true, true, $warning.' '.$result->message, $result->refusalReason, $result->activeOperationsRedriven);
        }

        if ($this->hasTerminalAgentEnd($sorted)) {
            if ($this->terminalReasonIs($sorted, $replayed, RunStatus::Failed, 'failed')) {
                return $this->repairTerminalFailedMalformedBatch(
                    runId: $runId,
                    apply: $apply,
                    sorted: $sorted,
                    replayed: $replayed,
                    storedState: $storedState,
                    commandId: $commandId,
                    leadingEvents: $leadingEvents,
                    leadingActions: $leadingActions,
                );
            }

            return $this->repairTerminalCancelledMalformedBatch(
                runId: $runId,
                apply: $apply,
                sorted: $sorted,
                replayed: $replayed,
                storedState: $storedState,
                commandId: $commandId,
                leadingEvents: $leadingEvents,
                leadingActions: $leadingActions,
            );
        }

        if (RunStatus::Cancelling !== $replayed->status) {
            $redrive = $this->currentOperationRedrive($runId, $apply, $sorted, $replayed, $commandId, $leadingEvents, $leadingActions);
            if (null !== $redrive) {
                return $redrive;
            }

            if ($this->hasUnresolvedPendingWork($replayed)) {
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
            commandId: $commandId,
            leadingEvents: $leadingEvents,
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
     * @param list<RunEvent> $leadingEvents
     * @param list<object>   $leadingActions
     */
    private function repairTerminalCancelledMalformedBatch(
        string $runId,
        bool $apply,
        array $sorted,
        RunState $replayed,
        RunState $storedState,
        string $commandId,
        array $leadingEvents,
        array $leadingActions,
    ): RepairResult {
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
            commandId: $commandId,
            leadingEvents: $leadingEvents,
            leadingActions: $leadingActions,
        );
    }

    /**
     * A failed result handler can persist agent_end(failed) after tool execution
     * without committing the result into message history. Append an explicit
     * unavailable result so a resumed session has a valid assistant/tool pair.
     *
     * @param list<RunEvent> $sorted
     * @param list<RunEvent> $leadingEvents
     * @param list<object>   $leadingActions
     */
    private function repairTerminalFailedMalformedBatch(
        string $runId,
        bool $apply,
        array $sorted,
        RunState $replayed,
        RunState $storedState,
        string $commandId,
        array $leadingEvents,
        array $leadingActions,
    ): RepairResult {
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
            commandId: $commandId,
            leadingEvents: $leadingEvents,
            leadingActions: $leadingActions,
        );
    }

    /**
     * @param list<RunEvent> $sorted
     * @param list<RunEvent> $leadingEvents
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
        string $commandId,
        array $leadingEvents,
        array $leadingActions,
    ): RepairResult {
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
            commandId: $commandId,
            leadingEvents: $leadingEvents,
            leadingActions: $leadingActions,
            requireValidToolCallSequence: true,
        );
    }

    /**
     * @param list<array{type: string, payload: array<string, mixed>}> $eventSpecs
     * @param list<RunEvent>                                           $sorted
     * @param list<RunEvent>                                           $leadingEvents
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
        string $commandId,
        bool $requireValidToolCallSequence = false,
        array $leadingEvents = [],
        array $leadingActions = [],
    ): RepairResult {
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
        $this->runCommit->commit($storedState, $persisted, [...$leadingEvents, ...$proposedEvents], dispatchAfterTurnHooks: false, postCommitActions: $leadingActions, sourceIdentity: $this->repairSource($runId, $commandId, $leadingActions));

        $this->logger->info('session_repair.completed', [
            'run_id' => $runId,
            'component' => 'session.repair',
            'event_type' => 'session.repair.completed',
            'terminal_events_appended' => \count($proposedEvents),
        ]);

        return new RepairResult(
            repairableStaleCancellationDetected: true,
            staleCancellationRepaired: true,
            message: $successMessage,
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
     * A manual /repair is explicit authorization to resend a bounded current
     * operation. It never appends a synthetic completion event: workers and
     * result handlers remain the authoritative completion path.
     *
     * @param list<RunEvent> $events
     * @param list<RunEvent> $leadingEvents
     * @param list<object>   $leadingActions
     */
    private function currentOperationRedrive(string $runId, bool $apply, array $events, RunState $state, string $commandId, array $leadingEvents, array $leadingActions): ?RepairResult
    {
        $events = $this->historyReplayFilter->filter($events);
        $effects = [];
        $operation = $state->currentOperation;

        // Validate shell reconstruction before collecting any LLM effect. A
        // historical shell event without standalone evidence is ambiguous; in
        // particular, it must not cause a legacy shell-seeded turn to be
        // redriven as a fabricated LLM operation.
        $shellEffects = [];
        $standaloneShellOperation = false;
        foreach ($state->pendingShellToolCalls as $toolCallId => $_) {
            $shell = $this->shellEffectFromEvents($runId, $toolCallId, $events);
            if (null === $shell) {
                return $this->refusalResult($runId, 'Session repair refused: current shell command cannot be reconstructed safely.', SessionRepairRefusalReasonEnum::AmbiguousPendingWork);
            }
            $shellEffects[] = $shell;
            $standaloneShellOperation = $standaloneShellOperation
                || (null !== $operation && $this->isStandaloneShellOperation($operation, $toolCallId, $events));
        }

        if (RunStatus::Compacting === $state->status) {
            if (null === $operation) {
                return $this->refusalResult($runId, 'Session repair refused: current compaction identity cannot be reconstructed safely.', SessionRepairRefusalReasonEnum::AmbiguousPendingWork);
            }

            $compaction = $this->executionOperations->repairDelivery($runId, $operation, ExecuteCompactionStep::class);
            if (null === $compaction) {
                return $this->refusalResult($runId, 'Session repair refused: current compaction authorization is missing.', SessionRepairRefusalReasonEnum::AmbiguousPendingWork);
            }
            $effects[] = $compaction;
        } elseif (null !== $operation && !$standaloneShellOperation) {
            $delivery = $this->executionOperations->repairDelivery($runId, $operation, ExecuteLlmStep::class);
            if (null === $delivery) {
                return $this->refusalResult($runId, 'Session repair refused: current LLM authorization is missing.', SessionRepairRefusalReasonEnum::AmbiguousPendingWork);
            }
            $effects[] = $delivery;
        }
        foreach ($shellEffects as $shell) {
            $delivery = $this->executionOperations->repairDelivery($runId, new CurrentOperationDTO($shell->turnNo(), $shell->stepId(), $shell->attempt(), $shell->idempotencyKey()), ExecuteShellToolCall::class);
            if (null === $delivery) {
                return $this->refusalResult($runId, 'Session repair refused: current shell authorization is missing.', SessionRepairRefusalReasonEnum::AmbiguousPendingWork);
            }
            $effects[] = $delivery;
        }

        if (null !== $state->activeStepId && [] !== $state->pendingToolCalls) {
            $batch = $this->toolBatchStore->load($runId, $state->turnNo, $state->activeStepId);
            if (null !== $batch && !$batch->finalized && [] === $batch->awaitingHumanInput) {
                foreach ($this->toolAuthorization->pendingDeliveries($runId, $state->turnNo, $state->activeStepId, $batch) as $delivery) {
                    if (!$delivery instanceof \Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown) {
                        $effects[] = $delivery;
                    }
                }
            }
        }

        if ([] === $effects && null === $operation && RunStatus::Running === $state->status && [] === $state->pendingToolCalls && [] === $state->pendingShellToolCalls) {
            $effects[] = new AdvanceRun(
                runId: $runId,
                turnNo: $state->turnNo,
                stepId: \sprintf('repair-advance-%d', $state->turnNo),
                attempt: 1,
                idempotencyKey: hash('sha256', \sprintf('%s|repair-advance|%d|%d', $runId, $state->turnNo, $state->lastSeq)),
            );
        }

        if ([] === $effects) {
            return null;
        }

        if (!$apply) {
            return new RepairResult(false, false, 'Active operation repair available.');
        }

        $stored = $this->activeRunContext->requireLoaded($runId);
        $this->runCommit->commit($stored, $state->with(['version' => $stored->version]), $leadingEvents, dispatchAfterTurnHooks: false,
            postCommitActions: [...$leadingActions, new \Ineersa\CodingAgent\Application\Message\RedriveRepairEffectsDTO($runId, $effects)],
            sourceIdentity: $this->repairSource($runId, $commandId, $leadingActions));

        return new RepairResult(false, false, 'Active operation redriven.', activeOperationsRedriven: \count($effects));
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
