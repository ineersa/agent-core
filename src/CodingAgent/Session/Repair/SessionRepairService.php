<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Repair;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
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
use Ineersa\AgentCore\Domain\Message\InvalidateRunContext;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\MalformedToolCallSequenceException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class SessionRepairService implements SessionRepairServiceInterface
{
    private const string SYNTHETIC_CANCEL_MESSAGE = 'Tool execution cancelled by user.';

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
        private StepDispatcher $stepDispatcher,
        private ToolBatchStoreInterface $toolBatchStore,
        private NormalizerInterface&DenormalizerInterface $serializer,
        private MessageBusInterface $commandBus,
    ) {
        $this->toolExecutionEndPayloadCodec = new ToolExecutionEndPayloadCodec($this->serializer);
    }

    public function repair(string $runId, bool $apply): RepairResult
    {
        return $this->lockManager->synchronized($runId, function () use ($runId, $apply): RepairResult {
            return $this->doRepair($runId, $apply);
        });
    }

    private function doRepair(string $runId, bool $apply): RepairResult
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
                terminalEventsAppended: 0,
                replayOk: null,
                message: 'Session repair refused: duplicate event sequences detected.',
                duplicateSeqs: $duplicateSeqs,
                refusalReason: SessionRepairRefusalReasonEnum::DuplicateSequences,
            );
        }

        $missingSeqs = $this->replayEventPreparer->missingSequences($sorted);
        if ([] !== $missingSeqs) {
            $this->logRefusal($runId, SessionRepairRefusalReasonEnum::MissingSequences, ['missing_count' => \count($missingSeqs)]);

            return new RepairResult(
                repairableStaleCancellationDetected: false,
                staleCancellationRepaired: false,
                terminalEventsAppended: 0,
                replayOk: null,
                message: 'Session repair refused: missing event sequences detected.',
                duplicateSeqs: [],
                refusalReason: SessionRepairRefusalReasonEnum::MissingSequences,
                missingSeqs: $missingSeqs,
            );
        }

        $storedState = $this->activeRunContext->stateFor($runId);

        if ($storedState->isStreaming) {
            $this->logRefusal($runId, SessionRepairRefusalReasonEnum::ActiveStreaming);

            return new RepairResult(
                repairableStaleCancellationDetected: false,
                staleCancellationRepaired: false,
                terminalEventsAppended: 0,
                replayOk: null,
                message: 'Session repair refused: active streaming detected.',
                refusalReason: SessionRepairRefusalReasonEnum::ActiveStreaming,
            );
        }

        $replayed = $this->runStateReducer->replay(RunState::queued($runId), $sorted);

        if ($this->hasTerminalAgentEnd($sorted)) {
            return $this->repairTerminalCancelledMalformedBatch(
                runId: $runId,
                apply: $apply,
                sorted: $sorted,
                replayed: $replayed,
                storedState: $storedState,
            );
        }

        if (RunStatus::Cancelling !== $replayed->status) {
            $redrive = $this->currentOperationRedrive($runId, $apply, $sorted, $replayed);
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
                terminalEventsAppended: 0,
                replayOk: null,
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
            requireCancelledStatus: true,
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
     */
    private function repairTerminalCancelledMalformedBatch(
        string $runId,
        bool $apply,
        array $sorted,
        RunState $replayed,
        RunState $storedState,
    ): RepairResult {
        if (!$this->hasCancellationContext($sorted)) {
            return $this->noRepairResult('No repairable corruption detected.');
        }

        if (!$this->isCancelledTerminal($sorted, $replayed)) {
            return $this->noRepairResult('No repairable corruption detected.');
        }

        $missingIds = $this->missingToolResultIds($replayed->messages);
        if (null === $missingIds) {
            // Append-only repair cannot reorder events, so unclosed-batch shapes are not repairable.
            return $this->noRepairResult('No repairable corruption detected: append-only repair cannot reorder events for unclosed tool-call batches.');
        }
        if ([] === $missingIds) {
            return $this->noRepairResult('No repairable corruption detected.');
        }

        if (!$apply) {
            return new RepairResult(
                repairableStaleCancellationDetected: true,
                staleCancellationRepaired: false,
                terminalEventsAppended: 0,
                replayOk: null,
                message: 'Terminal cancelled session has unmatched assistant tool calls; repair available.',
            );
        }

        $maxSeq = $this->replayEventPreparer->maxSequence($sorted);
        $turnNo = $replayed->turnNo;
        $stepId = $replayed->activeStepId ?? \sprintf('repair-cancel-%d', hrtime(true));
        $toolInfo = $this->toolCallInfoFromEvents($sorted);
        $eventSpecs = [];

        usort($missingIds, static function (string $a, string $b) use ($toolInfo): int {
            $orderA = $toolInfo[$a]['order_index'] ?? 0;
            $orderB = $toolInfo[$b]['order_index'] ?? 0;

            return $orderA <=> $orderB;
        });

        foreach ($missingIds as $toolCallId) {
            $info = $toolInfo[$toolCallId] ?? [];
            $toolName = \is_string($info['name'] ?? null) ? $info['name'] : 'unknown';
            $orderIndex = \is_int($info['order_index'] ?? null) ? $info['order_index'] : 0;

            if ($this->hasDurableToolEnd($sorted, $toolCallId)) {
                continue;
            }

            // No durable end yet — append one typed synthetic cancellation result.
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

        // ToolBatchCommitted is the retained ordering boundary. It flushes every
        // durable end for this incomplete batch, including ends that predate repair.
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
            successMessage: 'Terminal cancelled session repaired: missing tool messages appended.',
            requireCancelledStatus: true,
            requireValidToolCallSequence: true,
        );
    }

    /**
     * @param list<array{type: string, payload: array<string, mixed>}> $eventSpecs
     * @param list<RunEvent>                                           $sorted
     */
    private function appendProposedRepairEvents(
        string $runId,
        int $turnNo,
        int $maxSeq,
        array $eventSpecs,
        array $sorted,
        RunState $storedState,
        string $successMessage,
        bool $requireCancelledStatus,
        bool $requireValidToolCallSequence = false,
    ): RepairResult {
        $proposedEvents = $this->eventFactory->eventsFromSpecs($runId, $turnNo, $maxSeq + 1, $eventSpecs);
        $hypothetical = array_merge($sorted, $proposedEvents);
        $hypotheticalReplay = $this->runStateReducer->replay(RunState::queued($runId), $hypothetical);

        if ($requireCancelledStatus && RunStatus::Cancelled !== $hypotheticalReplay->status) {
            $this->logger->warning('session_repair.refused', [
                'run_id' => $runId,
                'component' => 'session.repair',
                'event_type' => 'session.repair.refused',
                'refusal_reason' => SessionRepairRefusalReasonEnum::ReplayValidationFailed->value,
                'final_status' => $hypotheticalReplay->status->value,
            ]);

            return new RepairResult(
                repairableStaleCancellationDetected: false,
                staleCancellationRepaired: false,
                terminalEventsAppended: 0,
                replayOk: false,
                message: 'Session repair refused: hypothetical replay did not reach Cancelled.',
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
                    terminalEventsAppended: 0,
                    replayOk: false,
                    message: 'Session repair refused: repaired message sequence remains malformed.',
                    refusalReason: SessionRepairRefusalReasonEnum::ReplayValidationFailed,
                );
            }
        }

        try {
            $this->eventStore->appendMany($proposedEvents);
        } catch (\Throwable $exception) {
            $this->logger->error('session_repair.append_failed', [
                'run_id' => $runId,
                'component' => 'session.repair',
                'event_type' => 'session.repair.append_failed',
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
            ]);

            throw $exception;
        }

        $persisted = $hypotheticalReplay->with([
            'version' => $storedState->version + 1,
            'isStreaming' => false,
            'streamingMessage' => null,
        ]);

        $this->activeRunContext->remember($persisted);

        // Event persistence and run-control invalidation are non-transactional:
        // a dispatch failure propagates after canonical events and the local projection are durable.
        $this->commandBus->dispatch(new InvalidateRunContext($runId));

        $this->logger->info('session_repair.completed', [
            'run_id' => $runId,
            'component' => 'session.repair',
            'event_type' => 'session.repair.completed',
            'terminal_events_appended' => \count($proposedEvents),
        ]);

        return new RepairResult(
            repairableStaleCancellationDetected: true,
            staleCancellationRepaired: true,
            terminalEventsAppended: \count($proposedEvents),
            replayOk: true,
            message: $successMessage,
        );
    }

    /**
     * @param list<RunEvent> $sorted
     */
    private function isCancelledTerminal(array $sorted, RunState $replayed): bool
    {
        if (RunStatus::Cancelled === $replayed->status) {
            return true;
        }

        foreach (array_reverse($sorted) as $event) {
            if (RunEventTypeEnum::AgentEnd->value !== $event->type) {
                continue;
            }

            return 'cancelled' === ($event->payload['reason'] ?? null);
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
        $syntheticResult = new ToolCallResult(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('repair-cancel-%s-%s', $runId, $toolCallId)),
            toolCallId: $toolCallId,
            orderIndex: $orderIndex,
            result: [
                'tool_name' => $toolName,
                'content' => [['type' => 'text', 'text' => self::SYNTHETIC_CANCEL_MESSAGE]],
            ],
            isError: true,
            error: [
                'type' => 'cancelled',
                'message' => self::SYNTHETIC_CANCEL_MESSAGE,
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
     */
    private function currentOperationRedrive(string $runId, bool $apply, array $events, RunState $state): ?RepairResult
    {
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

            $compaction = $this->compactionRequestFromStartedEvents($runId, $operation, $events);
            if (null === $compaction) {
                return $this->refusalResult($runId, 'Session repair refused: historical current compaction input cannot be reconstructed safely.', SessionRepairRefusalReasonEnum::AmbiguousPendingWork);
            }
            $effects[] = $compaction;
        } elseif (null !== $operation && !$standaloneShellOperation) {
            $effects[] = new ExecuteLlmStep(
                runId: $runId,
                turnNo: $operation->turnNo,
                stepId: $operation->stepId,
                attempt: $operation->attempt,
                idempotencyKey: $operation->idempotencyKey,
                contextRef: \sprintf('hot:run:%s', $runId),
                toolsRef: \sprintf('toolset:run:%s:turn:%d', $runId, $operation->turnNo),
                messages: $state->messages,
            );
        }
        $effects = [...$effects, ...$shellEffects];

        if (null !== $state->activeStepId && [] !== $state->pendingToolCalls) {
            $batch = $this->toolBatchStore->load($runId, $state->turnNo, $state->activeStepId);
            if (null !== $batch && !$batch->finalized && [] === $batch->awaitingHumanInput) {
                foreach ([...$batch->pendingQueue, ...array_keys($batch->inFlight)] as $toolCallId) {
                    if (isset($batch->calls[$toolCallId])) {
                        $effects[] = $batch->calls[$toolCallId];
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
            return new RepairResult(false, false, 0, null, 'Active operation repair available.');
        }

        $this->stepDispatcher->dispatchEffects($effects);

        return new RepairResult(false, false, 0, true, 'Active operation redriven.', activeOperationsRedriven: \count($effects));
    }

    /**
     * @param list<RunEvent> $events
     */
    private function compactionRequestFromStartedEvents(string $runId, CurrentOperationDTO $operation, array $events): ?ExecuteCompactionStep
    {
        foreach (array_reverse($events) as $event) {
            if (RunEventTypeEnum::ContextCompactionStarted->value !== $event->type
                || !$this->operationMatchesEvent($operation, $event)) {
                continue;
            }

            $workerRequest = $event->payload['worker_request'] ?? null;
            if (!\is_array($workerRequest)) {
                return null;
            }

            try {
                $request = $this->serializer->denormalize($workerRequest, ExecuteCompactionStep::class);
            } catch (\Throwable $exception) {
                $this->logger->warning('Session repair could not denormalize compaction worker request.', [
                    'event_type' => 'session.repair.compaction_denormalization_failed',
                    'run_id' => $runId,
                    'exception' => $exception::class,
                ]);

                return null;
            }

            if (!$request instanceof ExecuteCompactionStep
                || $request->runId() !== $runId
                || !$operation->matches($request->turnNo(), $request->stepId(), $request->attempt(), $request->idempotencyKey())) {
                return null;
            }

            return $request;
        }

        return null;
    }

    private function operationMatchesEvent(CurrentOperationDTO $operation, RunEvent $event): bool
    {
        return $operation->turnNo === ($event->payload['turn_no'] ?? null)
            && $operation->stepId === ($event->payload['step_id'] ?? null)
            && $operation->attempt === ($event->payload['operation_attempt'] ?? null)
            && $operation->idempotencyKey === ($event->payload['operation_idempotency_key'] ?? null);
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
                $runId,
                $operation->turnNo,
                $toolCallId,
                ltrim(substr($text, 1)),
                $standalone,
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
            terminalEventsAppended: 0,
            replayOk: null,
            message: 'Session repair refused: ambiguous pending work.',
            refusalReason: SessionRepairRefusalReasonEnum::AmbiguousPendingWork,
        );
    }

    private function noRepairResult(string $message): RepairResult
    {
        return new RepairResult(
            repairableStaleCancellationDetected: false,
            staleCancellationRepaired: false,
            terminalEventsAppended: 0,
            replayOk: null,
            message: $message,
        );
    }

    private function refusalResult(string $runId, string $message, SessionRepairRefusalReasonEnum $reason): RepairResult
    {
        $this->logRefusal($runId, $reason);

        return new RepairResult(
            repairableStaleCancellationDetected: false,
            staleCancellationRepaired: false,
            terminalEventsAppended: 0,
            replayOk: SessionRepairRefusalReasonEnum::ReplayValidationFailed === $reason ? false : null,
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
