<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Repair;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Psr\Log\LoggerInterface;

final readonly class SessionRepairService implements SessionRepairServiceInterface
{
    private const string SYNTHETIC_CANCEL_MESSAGE = 'Tool execution cancelled by user.';

    public function __construct(
        private EventStoreInterface $eventStore,
        private RunStoreInterface $runStore,
        private RunStateReducer $runStateReducer,
        private ReplayEventPreparer $replayEventPreparer,
        private EventFactory $eventFactory,
        private AgentMessageNormalizer $messageNormalizer,
        private RunLockManager $lockManager,
        private LoggerInterface $logger,
    ) {
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

        $storedState = $this->runStore->get($runId);
        if (null === $storedState) {
            return $this->refusalResult(
                runId: $runId,
                message: 'Session repair refused: run state is unavailable.',
                reason: SessionRepairRefusalReasonEnum::RunStateUnavailable,
            );
        }

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
            return $this->noRepairResult('No repairable corruption detected.');
        }

        if (RunStatus::Cancelling !== $replayed->status) {
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

        if ($this->llmStepRemainedIncomplete($sorted)) {
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

        $proposedEvents = $this->eventFactory->eventsFromSpecs($runId, $turnNo, $maxSeq + 1, $eventSpecs);
        $hypothetical = array_merge($sorted, $proposedEvents);
        $hypotheticalReplay = $this->runStateReducer->replay(RunState::queued($runId), $hypothetical);

        if (RunStatus::Cancelled !== $hypotheticalReplay->status) {
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

        $finalReplay = $hypotheticalReplay;

        $persisted = new RunState(
            runId: $finalReplay->runId,
            status: $finalReplay->status,
            version: $storedState->version + 1,
            turnNo: $finalReplay->turnNo,
            lastSeq: $finalReplay->lastSeq,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: $finalReplay->pendingToolCalls,
            errorMessage: $finalReplay->errorMessage,
            messages: $finalReplay->messages,
            activeStepId: $finalReplay->activeStepId,
            retryableFailure: $finalReplay->retryableFailure,
            retryAttempts: $finalReplay->retryAttempts,
        );

        if (!$this->runStore->compareAndSwap($persisted, $storedState->version)) {
            $this->logger->warning('session_repair.cas_degraded', [
                'run_id' => $runId,
                'component' => 'session.repair',
                'event_type' => 'session.repair.cas_degraded',
                'terminal_events_appended' => \count($proposedEvents),
            ]);

            return new RepairResult(
                repairableStaleCancellationDetected: true,
                staleCancellationRepaired: true,
                terminalEventsAppended: \count($proposedEvents),
                replayOk: true,
                message: 'Session repaired: canonical events appended; hot run state will self-heal on replay.',
            );
        }

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
            message: 'Stale non-terminal cancellation repaired.',
        );
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
            'type' => RunEventTypeEnum::ToolCallResultReceived->value,
            'payload' => [
                'tool_call_id' => $toolCallId,
                'order_index' => $orderIndex,
                'is_error' => true,
            ],
        ];
        $eventSpecs[] = [
            'type' => RunEventTypeEnum::ToolExecutionEnd->value,
            'payload' => [
                'tool_call_id' => $toolCallId,
                'order_index' => $orderIndex,
                'is_error' => true,
                'result' => self::SYNTHETIC_CANCEL_MESSAGE,
                'cancelled' => true,
                'cancellation_reason' => 'user',
            ],
        ];

        $toolMsg = $this->messageNormalizer->toolMessage($syntheticResult);
        $toolMsgArray = $toolMsg->toArray();

        $eventSpecs[] = [
            'type' => RunEventTypeEnum::MessageStart->value,
            'payload' => [
                'message_role' => 'tool',
                'tool_call_id' => $toolCallId,
            ],
        ];
        $eventSpecs[] = [
            'type' => RunEventTypeEnum::MessageEnd->value,
            'payload' => [
                'message_role' => 'tool',
                'tool_call_id' => $toolCallId,
                'message' => $toolMsgArray,
            ],
        ];
    }

    /**
     * @param list<RunEvent> $events
     */
    private function hasTerminalAgentEnd(array $events): bool
    {
        foreach ($events as $event) {
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
     * Tracks the latest open assistant phase across canonical events (message_start/end,
     * llm_step_completed/aborted, turn_advanced). Returns true when an assistant message_start
     * was never closed by a matching message_end or terminal LLM step event — i.e. cancellation
     * abandoned an in-flight assistant turn that still needs an llm_step_aborted append.
     *
     * @param list<RunEvent> $events
     */
    private function llmStepRemainedIncomplete(array $events): bool
    {
        $openAssistantMessageId = null;
        $openStepId = null;

        foreach ($events as $event) {
            if (RunEventTypeEnum::MessageStart->value === $event->type) {
                $role = \is_string($event->payload['message_role'] ?? null) ? $event->payload['message_role'] : null;
                if ('assistant' === $role) {
                    $openAssistantMessageId = \is_string($event->payload['message_id'] ?? null) ? $event->payload['message_id'] : 'assistant';
                    $openStepId = null;
                }

                continue;
            }

            if (RunEventTypeEnum::MessageEnd->value === $event->type) {
                $role = \is_string($event->payload['message_role'] ?? null) ? $event->payload['message_role'] : null;
                if ('assistant' === $role) {
                    $messageId = \is_string($event->payload['message_id'] ?? null) ? $event->payload['message_id'] : null;
                    if (null === $openAssistantMessageId || null === $messageId || $messageId === $openAssistantMessageId) {
                        $openAssistantMessageId = null;
                    }
                }

                continue;
            }

            if (RunEventTypeEnum::LlmStepCompleted->value === $event->type) {
                $stepId = \is_string($event->payload['step_id'] ?? null) ? $event->payload['step_id'] : null;
                if (null === $openStepId || (null !== $stepId && $stepId === $openStepId)) {
                    $openAssistantMessageId = null;
                    $openStepId = null;
                }

                continue;
            }

            if (RunEventTypeEnum::LlmStepAborted->value === $event->type) {
                $stepId = \is_string($event->payload['step_id'] ?? null) ? $event->payload['step_id'] : null;
                if (null === $openStepId || (null !== $stepId && $stepId === $openStepId)) {
                    $openAssistantMessageId = null;
                    $openStepId = null;
                }

                continue;
            }

            if (RunEventTypeEnum::TurnAdvanced->value === $event->type) {
                $openStepId = \is_string($event->payload['step_id'] ?? null) ? $event->payload['step_id'] : $openStepId;
            }
        }

        return null !== $openAssistantMessageId;
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
            if (($event->payload['tool_call_id'] ?? null) === $toolCallId) {
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
