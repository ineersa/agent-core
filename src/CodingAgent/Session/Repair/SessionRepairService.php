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
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Psr\Log\LoggerInterface;

readonly class SessionRepairService
{
    private const string SYNTHETIC_CANCEL_MESSAGE = 'Tool execution cancelled by user.';

    public function __construct(
        private EventStoreInterface $eventStore,
        private RunStoreInterface $runStore,
        private RunStateReducer $runStateReducer,
        private ReplayEventPreparer $replayEventPreparer,
        private EventFactory $eventFactory,
        /** @phpstan-ignore property.onlyWritten (accepted RED test constructor contract) */
        private EventPayloadNormalizer $eventPayloadNormalizer,
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
                message: 'No canonical events found for session repair.',
                reason: SessionRepairRefusalReasonEnum::NoEvents,
            );
        }

        $sorted = $this->replayEventPreparer->sortBySequence($events);
        $duplicateSeqs = $this->replayEventPreparer->duplicateSequences($sorted);
        if ([] !== $duplicateSeqs) {
            $this->logger->warning('session_repair.refused', [
                'run_id' => $runId,
                'component' => 'session.repair',
                'event_type' => 'session.repair.refused',
                'refusal_reason' => SessionRepairRefusalReasonEnum::DuplicateSequences->value,
                'duplicate_count' => \count($duplicateSeqs),
            ]);

            return new RepairResult(
                needsRepair: true,
                staleCancellationRepaired: false,
                terminalEventsAppended: 0,
                replayOk: false,
                message: 'Session repair refused: duplicate event sequences detected.',
                duplicateSeqs: $duplicateSeqs,
                backupEventsPath: null,
                refusalReason: SessionRepairRefusalReasonEnum::DuplicateSequences,
            );
        }

        $missingSeqs = $this->replayEventPreparer->missingSequences($sorted);
        if ([] !== $missingSeqs) {
            $this->logger->warning('session_repair.refused', [
                'run_id' => $runId,
                'component' => 'session.repair',
                'event_type' => 'session.repair.refused',
                'refusal_reason' => SessionRepairRefusalReasonEnum::MissingSequences->value,
                'missing_count' => \count($missingSeqs),
            ]);

            return new RepairResult(
                needsRepair: true,
                staleCancellationRepaired: false,
                terminalEventsAppended: 0,
                replayOk: false,
                message: 'Session repair refused: missing event sequences detected.',
                duplicateSeqs: [],
                backupEventsPath: null,
                refusalReason: SessionRepairRefusalReasonEnum::MissingSequences,
                missingSeqs: $missingSeqs,
            );
        }

        $storedState = $this->runStore->get($runId);
        if (null === $storedState) {
            return $this->refusalResult(
                message: 'Session repair refused: run state is unavailable.',
                reason: SessionRepairRefusalReasonEnum::RunStateUnavailable,
            );
        }

        if ($storedState->isStreaming) {
            $this->logger->warning('session_repair.refused', [
                'run_id' => $runId,
                'component' => 'session.repair',
                'event_type' => 'session.repair.refused',
                'refusal_reason' => SessionRepairRefusalReasonEnum::ActiveStreaming->value,
            ]);

            return new RepairResult(
                needsRepair: true,
                staleCancellationRepaired: false,
                terminalEventsAppended: 0,
                replayOk: false,
                message: 'Session repair refused: active streaming detected.',
                duplicateSeqs: [],
                backupEventsPath: null,
                refusalReason: SessionRepairRefusalReasonEnum::ActiveStreaming,
            );
        }

        $replayed = $this->runStateReducer->replay(RunState::queued($runId), $sorted);

        if ($this->hasTerminalAgentEnd($sorted)) {
            return new RepairResult(
                needsRepair: false,
                staleCancellationRepaired: false,
                terminalEventsAppended: 0,
                replayOk: true,
                message: 'No repairable corruption detected.',
                duplicateSeqs: [],
                backupEventsPath: null,
            );
        }

        if (RunStatus::Cancelling !== $replayed->status) {
            if ($this->hasUnresolvedPendingWork($replayed)) {
                return $this->ambiguousRefusal($runId);
            }

            return new RepairResult(
                needsRepair: false,
                staleCancellationRepaired: false,
                terminalEventsAppended: 0,
                replayOk: true,
                message: 'No repairable corruption detected.',
                duplicateSeqs: [],
                backupEventsPath: null,
            );
        }

        $cancelContext = $this->hasCancellationContext($sorted);
        $unresolvedIds = $this->unresolvedPendingToolCallIds($replayed);

        if ([] !== $unresolvedIds && !$cancelContext) {
            return $this->ambiguousRefusal($runId);
        }

        if (!$apply) {
            return new RepairResult(
                needsRepair: true,
                staleCancellationRepaired: false,
                terminalEventsAppended: 0,
                replayOk: false,
                message: 'Stale non-terminal cancellation detected; repair available.',
                duplicateSeqs: [],
                backupEventsPath: null,
            );
        }

        $maxSeq = $this->replayEventPreparer->maxSequence($sorted);
        $turnNo = $replayed->turnNo;
        $eventSpecs = [];

        if (null !== $replayed->activeStepId && '' !== $replayed->activeStepId) {
            $eventSpecs[] = [
                'type' => RunEventTypeEnum::LlmStepAborted->value,
                'payload' => [
                    'step_id' => $replayed->activeStepId,
                    'stop_reason' => 'cancelled',
                    'usage' => null,
                    'aborted_assistant' => null,
                ],
            ];
        }

        $resolvedCount = 0;
        if ([] !== $unresolvedIds) {
            $toolInfo = $this->toolCallInfoFromEvents($sorted);
            foreach ($unresolvedIds as $toolCallId) {
                $info = $toolInfo[$toolCallId] ?? [];
                $toolName = \is_string($info['name'] ?? null) ? $info['name'] : 'unknown';
                $orderIndex = \is_int($info['order_index'] ?? null) ? $info['order_index'] : 0;

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
                ++$resolvedCount;
            }

            $eventSpecs[] = [
                'type' => RunEventTypeEnum::ToolBatchCommitted->value,
                'payload' => [
                    'count' => $resolvedCount,
                    'turn_no' => $turnNo,
                    'step_id' => $replayed->activeStepId,
                ],
            ];
        }

        $eventSpecs[] = [
            'type' => RunEventTypeEnum::AgentEnd->value,
            'payload' => [
                'reason' => 'cancelled',
            ],
        ];

        $newEvents = $this->eventFactory->eventsFromSpecs($runId, $turnNo, $maxSeq + 1, $eventSpecs);
        foreach ($newEvents as $event) {
            $this->eventStore->append($event);
        }

        $allEvents = $this->eventStore->allFor($runId);
        $allSorted = $this->replayEventPreparer->sortBySequence($allEvents);
        $finalReplay = $this->runStateReducer->replay(RunState::queued($runId), $allSorted);

        if (RunStatus::Cancelled !== $finalReplay->status) {
            $this->logger->error('session_repair.replay_validation_failed', [
                'run_id' => $runId,
                'component' => 'session.repair',
                'event_type' => 'session.repair.replay_validation_failed',
                'final_status' => $finalReplay->status->value,
            ]);

            return new RepairResult(
                needsRepair: true,
                staleCancellationRepaired: false,
                terminalEventsAppended: \count($newEvents),
                replayOk: false,
                message: 'Session repair appended events but replay did not reach Cancelled.',
                duplicateSeqs: [],
                backupEventsPath: null,
            );
        }

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
            $this->logger->warning('session_repair.refused', [
                'run_id' => $runId,
                'component' => 'session.repair',
                'event_type' => 'session.repair.refused',
                'refusal_reason' => SessionRepairRefusalReasonEnum::CompareAndSwapConflict->value,
            ]);

            return new RepairResult(
                needsRepair: true,
                staleCancellationRepaired: false,
                terminalEventsAppended: \count($newEvents),
                replayOk: true,
                message: 'Session repair refused: run state changed during repair.',
                duplicateSeqs: [],
                backupEventsPath: null,
                refusalReason: SessionRepairRefusalReasonEnum::CompareAndSwapConflict,
            );
        }

        $this->logger->info('session_repair.completed', [
            'run_id' => $runId,
            'component' => 'session.repair',
            'event_type' => 'session.repair.completed',
            'terminal_events_appended' => \count($newEvents),
        ]);

        return new RepairResult(
            needsRepair: true,
            staleCancellationRepaired: true,
            terminalEventsAppended: \count($newEvents),
            replayOk: true,
            message: 'Stale non-terminal cancellation repaired.',
            duplicateSeqs: [],
            backupEventsPath: null,
        );
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
        $order = 0;

        foreach ($events as $event) {
            if (RunEventTypeEnum::LlmStepCompleted->value === $event->type) {
                $assistant = \is_array($event->payload['assistant_message'] ?? null) ? $event->payload['assistant_message'] : [];
                $toolCalls = \is_array($assistant['tool_calls'] ?? null) ? $assistant['tool_calls'] : [];
                foreach ($toolCalls as $toolCall) {
                    if (!\is_array($toolCall)) {
                        continue;
                    }
                    $id = \is_string($toolCall['id'] ?? null) ? $toolCall['id'] : null;
                    if (null === $id) {
                        continue;
                    }
                    $function = \is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
                    $name = \is_string($function['name'] ?? null) ? $function['name'] : 'unknown';
                    $map[$id] = ['name' => $name, 'order_index' => $order];
                    ++$order;
                }
            }

            if (RunEventTypeEnum::ToolExecutionStart->value === $event->type) {
                $id = \is_string($event->payload['tool_call_id'] ?? null) ? $event->payload['tool_call_id'] : null;
                if (null === $id) {
                    continue;
                }
                $name = \is_string($event->payload['tool_name'] ?? null) ? $event->payload['tool_name'] : ($map[$id]['name'] ?? 'unknown');
                if (!isset($map[$id])) {
                    $map[$id] = ['name' => $name, 'order_index' => $order];
                    ++$order;
                }
            }
        }

        return $map;
    }

    private function ambiguousRefusal(string $runId): RepairResult
    {
        $this->logger->warning('session_repair.refused', [
            'run_id' => $runId,
            'component' => 'session.repair',
            'event_type' => 'session.repair.refused',
            'refusal_reason' => SessionRepairRefusalReasonEnum::AmbiguousPendingWork->value,
        ]);

        return new RepairResult(
            needsRepair: true,
            staleCancellationRepaired: false,
            terminalEventsAppended: 0,
            replayOk: false,
            message: 'Session repair refused: ambiguous pending work.',
            duplicateSeqs: [],
            backupEventsPath: null,
            refusalReason: SessionRepairRefusalReasonEnum::AmbiguousPendingWork,
        );
    }

    private function refusalResult(string $message, SessionRepairRefusalReasonEnum $reason): RepairResult
    {
        return new RepairResult(
            needsRepair: false,
            staleCancellationRepaired: false,
            terminalEventsAppended: 0,
            replayOk: false,
            message: $message,
            duplicateSeqs: [],
            backupEventsPath: null,
            refusalReason: $reason,
        );
    }
}
