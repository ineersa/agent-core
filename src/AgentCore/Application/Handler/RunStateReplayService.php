<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Application\Replay\TurnTreeReplayFilter;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds AgentCore {@see RunState} from the canonical event stream.
 *
 * Replay order: sort by seq ascending, verify contiguity, reduce events
 * into a RunState with status, turn, messages, pending tool calls,
 * errors, and retryability.  The rebuilt state preserves the version
 * from the existing stored state (or 0 when missing) so CAS-based
 * writers can safely continue; the CAS version is a concurrency
 * counter, not derivable from events.
 *
 * Callers should check {@see RunStateReplayResult::$rebuilt} to decide
 * whether to persist the replayed state before advancing the run.
 */
final readonly class RunStateReplayService
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private LoggerInterface $logger,
        private ?TurnTreeReplayFilter $turnTreeReplayFilter = null,
    ) {
    }

    /**
     * Rebuilds RunState from events if the stored state is missing or stale.
     *
     * - No events → returns RunStateReplayResult::noEvents().
     * - State is current (stored lastSeq >= max event seq) → returns RunStateReplayResult::current().
     * - State is stale or missing → replays and returns RunStateReplayResult::rebuilt().
     * - Non-contiguous history → logs the gap but does NOT return a rebuilt state
     *   (throws an exception; caller should not proceed with partial state).
     *
     * @throws RunStateReplayException when event history is non-contiguous
     */
    public function rebuildIfStale(RunState $state, string $runId): RunStateReplayResult
    {
        $events = $this->eventStore->allFor($runId);

        if ([] === $events) {
            return RunStateReplayResult::noEvents();
        }

        $sortedEvents = $this->sortBySequence($events);
        $maxEventSeq = $this->maxSequence($sortedEvents);

        // Stored state is current — no rebuild needed.
        if ($state->lastSeq >= $maxEventSeq) {
            return RunStateReplayResult::current($maxEventSeq, \count($sortedEvents));
        }

        RunLogContext::enter(['run_id' => $runId, 'component' => 'replay']);

        try {
            // Detect duplicates before contiguity check so the diagnostic
            // reports the right failure reason and replay never processes
            // duplicate sequences.
            $duplicateSeqs = $this->duplicateSequences($sortedEvents);
            if ([] !== $duplicateSeqs) {
                $this->logger->error('run_state_replay.duplicate_sequences', [
                    'run_id' => $runId,
                    'event_count' => \count($sortedEvents),
                    'duplicate_sequences' => $duplicateSeqs,
                    'duplicate_count' => \count($duplicateSeqs),
                ]);

                throw new RunStateReplayException(\sprintf('Cannot replay run %s: event history contains %d duplicate sequence number(s): %s.', $runId, \count($duplicateSeqs), implode(', ', array_map('strval', \array_slice($duplicateSeqs, 0, 10)))));
            }

            $missingSequences = $this->missingSequences($sortedEvents);
            $isContiguous = [] === $missingSequences;

            $this->logger->info('run_state_replay.rebuilding', [
                'run_id' => $runId,
                'state_last_seq' => $state->lastSeq,
                'event_last_seq' => $maxEventSeq,
                'event_count' => \count($sortedEvents),
                'is_contiguous' => $isContiguous,
                'missing_sequences_count' => \count($missingSequences),
            ]);

            if (!$isContiguous) {
                $this->logger->error('run_state_replay.non_contiguous', [
                    'run_id' => $runId,
                    'state_last_seq' => $state->lastSeq,
                    'event_last_seq' => $maxEventSeq,
                    'event_count' => \count($sortedEvents),
                    'missing_sequences' => $missingSequences,
                ]);

                throw new RunStateReplayException(\sprintf('Cannot replay run %s: event history has %d missing sequences. Expected contiguous range 1..%d, found gaps at: %s.', $runId, \count($missingSequences), $maxEventSeq, implode(', ', array_map('strval', \array_slice($missingSequences, 0, 10)))));
            }

            // Filter to active branch events when tree metadata is available.
            // Abandoned sibling branch events (message/tool/assistant content for
            // turns not on the active path) are excluded from replay while the
            // canonical stream integrity checks remain on the full sorted stream.
            $filteredEvents = $sortedEvents;
            if (null !== $this->turnTreeReplayFilter) {
                $branchReplay = $this->turnTreeReplayFilter->filter($runId, $sortedEvents);
                $filteredEvents = $branchReplay->events;

                $this->logger->info('run_state_replay.branch_filtered', [
                    'run_id' => $runId,
                    'canonical_event_count' => $branchReplay->canonicalEventCount,
                    'filtered_event_count' => \count($filteredEvents),
                    'current_leaf_turn_no' => $branchReplay->currentLeafTurnNo,
                    'active_branch_turns' => $branchReplay->activePathTurnNos,
                ]);
            }

            $rebuiltState = $this->replay($state, $filteredEvents);

            // After replay, ensure lastSeq reflects the full canonical stream
            // so state is current with respect to the append-only event log,
            // even when replaying an earlier branch/leaf.
            $rebuiltState = new RunState(
                runId: $rebuiltState->runId,
                status: $rebuiltState->status,
                version: $rebuiltState->version,
                turnNo: $rebuiltState->turnNo,
                lastSeq: $maxEventSeq,
                isStreaming: $rebuiltState->isStreaming,
                streamingMessage: $rebuiltState->streamingMessage,
                pendingToolCalls: $rebuiltState->pendingToolCalls,
                errorMessage: $rebuiltState->errorMessage,
                messages: $rebuiltState->messages,
                activeStepId: $rebuiltState->activeStepId,
                retryableFailure: $rebuiltState->retryableFailure,
                retryAttempts: $rebuiltState->retryAttempts,
            );

            $this->logger->info('run_state_replay.rebuilt', [
                'run_id' => $runId,
                'replayed_seq_count' => \count($sortedEvents),
                'rebuilt_message_count' => \count($rebuiltState->messages),
                'rebuilt_status' => $rebuiltState->status->value,
                'rebuilt_turn_no' => $rebuiltState->turnNo,
            ]);

            return RunStateReplayResult::rebuilt(
                $rebuiltState,
                $maxEventSeq,
                \count($sortedEvents),
                $isContiguous,
                $missingSequences,
            );
        } finally {
            RunLogContext::leave();
        }
    }

    /**
     * Replays the full event history into a RunState.
     *
     * The returned state preserves the current stored version for CAS
     * correctness.  The state is built from scratch: only {status, turnNo,
     * lastSeq, activeStepId, pendingToolCalls, errorMessage, messages,
     * retryableFailure} are populated from events; isStreaming is false
     * and streamingMessage is null after replay.
     *
     * **Caller must provide events sorted ascending by sequence.**
     * Events MAY have gaps (non-contiguous sequences) when branch-filtered;
     * canonical duplicate/contiguity validation happens on the full event
     * stream in {@see rebuildIfStale()} before branch filtering.
     * This method does NOT detect gaps, validate ordering, or check for
     * duplicate sequences.
     *
     * **By-ref accumulator invariant:** Reducers mutate {@see $messages} and
     * {@see $pendingToolCalls} by reference as the source of truth for the
     * replayed collections.  Intermediate {@see RunState} objects produced by
     * each iteration carry stale copies of {@see RunState::$messages} and
     * {@see RunState::$pendingToolCalls}; only the final copy built after the
     * loop reflects the complete replayed state.  Future reducers MUST NOT
     * rely on {@see $state->messages} or {@see $state->pendingToolCalls}
     * for current accumulator state during iteration.
     *
     * @param list<RunEvent> $events sorted ascending by seq; may have gaps after branch filtering
     */
    public function replay(RunState $existingState, array $events): RunState
    {
        $state = new RunState(
            runId: $existingState->runId,
            status: RunStatus::Queued,
            version: $existingState->version,
            turnNo: 0,
            lastSeq: 0,
        );

        // By-ref accumulators: reducers append to these arrays; intermediate
        // RunState objects carry stale copies (see docblock invariant above).
        $messages = [];
        $pendingToolCalls = [];

        foreach ($events as $event) {
            $state = $this->applyEvent($state, $event, $messages, $pendingToolCalls);

            // Advance lastSeq to the current event's sequence number.
            $state = new RunState(
                runId: $state->runId,
                status: $state->status,
                version: $state->version,
                turnNo: $state->turnNo,
                lastSeq: $event->seq,
                isStreaming: $state->isStreaming,
                streamingMessage: $state->streamingMessage,
                pendingToolCalls: $state->pendingToolCalls,
                errorMessage: $state->errorMessage,
                messages: $state->messages,
                activeStepId: $state->activeStepId,
                retryableFailure: $state->retryableFailure,
                retryAttempts: $state->retryAttempts,
            );
        }

        // Copy mutable collections back into a new RunState with final values.
        return new RunState(
            runId: $state->runId,
            status: $state->status,
            version: $state->version,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: $pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $messages,
            activeStepId: $state->activeStepId,
            retryableFailure: $state->retryableFailure,
            retryAttempts: $state->retryAttempts,
        );
    }

    /**
     * @param list<AgentMessage>  $messages
     * @param array<string, bool> $pendingToolCalls
     */
    private function applyEvent(
        RunState $state,
        RunEvent $event,
        array &$messages,
        array &$pendingToolCalls,
    ): RunState {
        $payload = $event->payload;

        return match ($event->type) {
            RunEventTypeEnum::RunStarted->value => $this->applyRunStarted($event, $state, $messages),
            RunEventTypeEnum::TurnAdvanced->value => $this->applyTurnAdvanced($payload, $state),
            RunEventTypeEnum::AgentCommandApplied->value => $this->applyAgentCommandApplied($payload, $state, $messages),
            RunEventTypeEnum::AgentCommandRejected->value => $this->applyCommandRejected($payload, $state),
            RunEventTypeEnum::LlmStepCompleted->value => $this->applyLlmStepCompleted($payload, $state, $messages, $pendingToolCalls),
            RunEventTypeEnum::LlmStepFailed->value => $this->applyLlmStepFailed($payload, $state),
            RunEventTypeEnum::LlmStepAborted->value => $this->applyNoMutation($event, $state),
            RunEventTypeEnum::ToolExecutionStart->value => $this->applyToolExecutionStart($payload, $pendingToolCalls, $state),
            RunEventTypeEnum::ToolExecutionEnd->value => $this->applyToolExecutionEnd($payload, $pendingToolCalls, $state),
            RunEventTypeEnum::ToolCallResultReceived->value => $this->applyNoMutation($event, $state),
            RunEventTypeEnum::MessageStart->value => $this->applyNoMutation($event, $state),
            RunEventTypeEnum::MessageEnd->value => $this->applyMessageEnd($payload, $state, $messages),
            RunEventTypeEnum::ToolBatchCommitted->value => $this->applyToolBatchCommitted($state, $pendingToolCalls),
            RunEventTypeEnum::WaitingHuman->value => $this->applyWaitingHuman($state),
            RunEventTypeEnum::AgentEnd->value => $this->applyAgentEnd($payload, $state),
            RunEventTypeEnum::AgentStart->value,
            RunEventTypeEnum::TurnStart->value,
            RunEventTypeEnum::MessageUpdate->value,
            RunEventTypeEnum::ToolExecutionUpdate->value,
            RunEventTypeEnum::TurnEnd->value,
            RunEventTypeEnum::AgentCommandQueued->value,
            RunEventTypeEnum::AgentCommandSuperseded->value,
            RunEventTypeEnum::StaleResultIgnored->value => $this->applyNoMutation($event, $state),
            RunEventTypeEnum::ContextCompactionStarted->value => $this->applyContextCompactionStarted($payload, $state),
            RunEventTypeEnum::ContextCompacted->value => $this->applyContextCompacted($payload, $state, $messages),
            RunEventTypeEnum::ContextCompactionFailed->value => $this->applyContextCompactionFailed($payload, $state),
            RunEventTypeEnum::TurnBranched->value,
            RunEventTypeEnum::LeafSet->value => $this->applyNoMutation($event, $state),
            default => $this->applyNoMutation($event, $state),
        };
    }

    // ── Event reducers ──────────────────────────────────────────────────────

    /**
     * @param list<AgentMessage> $messages
     */
    private function applyRunStarted(RunEvent $event, RunState $state, array &$messages): RunState
    {
        $payload = $event->payload;
        $stepId = \is_string($payload['step_id'] ?? null) ? $payload['step_id'] : null;

        // Initial messages are nested under payload.payload.messages
        // (StartRunHandler normalizes the StartRunPayload into the event).
        $innerPayload = \is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $rawMessages = \is_array($innerPayload['messages'] ?? null) ? $innerPayload['messages'] : [];

        foreach ($rawMessages as $rawMessage) {
            if (!\is_array($rawMessage)) {
                continue;
            }

            $msg = AgentMessage::fromPayload($rawMessage);
            if (null !== $msg) {
                $messages[] = $msg;
            }
        }

        return new RunState(
            runId: $state->runId,
            status: RunStatus::Running,
            version: $state->version,
            turnNo: 0,
            lastSeq: $event->seq,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: $state->messages, // placeholder; actual messages in $messages by-ref
            activeStepId: $stepId,
            retryableFailure: false,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyTurnAdvanced(array $payload, RunState $state): RunState
    {
        $turnNo = \is_int($payload['turn_no'] ?? null) ? $payload['turn_no'] : $state->turnNo;
        $stepId = \is_string($payload['step_id'] ?? null) ? $payload['step_id'] : $state->activeStepId;

        return new RunState(
            runId: $state->runId,
            status: RunStatus::Running,
            version: $state->version,
            turnNo: $turnNo,
            lastSeq: $state->lastSeq,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: null,
            messages: $state->messages,
            activeStepId: $stepId,
            retryableFailure: false,
            retryAttempts: $state->retryAttempts,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<AgentMessage>   $messages
     */
    private function applyAgentCommandApplied(array $payload, RunState $state, array &$messages): RunState
    {
        $kind = \is_string($payload['kind'] ?? null) ? $payload['kind'] : null;

        // steer / follow_up / append_message: append message to prompt context
        if (\in_array($kind, ['steer', 'follow_up', 'append_message'], true)) {
            $messagePayload = \is_array($payload['message'] ?? null) ? $payload['message'] : null;
            if (null !== $messagePayload) {
                $msg = AgentMessage::fromPayload($messagePayload);
                if (null !== $msg) {
                    $messages[] = $msg;
                }
            }

            return new RunState(
                runId: $state->runId,
                status: RunStatus::Running,
                version: $state->version,
                turnNo: $state->turnNo,
                lastSeq: $state->lastSeq,
                isStreaming: $state->isStreaming,
                streamingMessage: $state->streamingMessage,
                pendingToolCalls: $state->pendingToolCalls,
                errorMessage: null,
                messages: $state->messages,
                activeStepId: $state->activeStepId,
                retryableFailure: false,
            );
        }

        // human_response: append message and transition to Running
        if ('human_response' === $kind) {
            $messagePayload = \is_array($payload['message'] ?? null) ? $payload['message'] : null;
            if (null !== $messagePayload) {
                $msg = AgentMessage::fromPayload($messagePayload);
                if (null !== $msg) {
                    $messages[] = $msg;
                }
            }

            return new RunState(
                runId: $state->runId,
                status: RunStatus::Running,
                version: $state->version,
                turnNo: $state->turnNo,
                lastSeq: $state->lastSeq,
                isStreaming: $state->isStreaming,
                streamingMessage: $state->streamingMessage,
                pendingToolCalls: $state->pendingToolCalls,
                errorMessage: null,
                messages: $state->messages,
                activeStepId: $state->activeStepId,
                retryableFailure: false,
            );
        }

        // cancel: transition to Cancelling
        if ('cancel' === $kind) {
            $reason = \is_string($payload['reason'] ?? null) ? $payload['reason'] : null;

            return new RunState(
                runId: $state->runId,
                status: RunStatus::Cancelling,
                version: $state->version,
                turnNo: $state->turnNo,
                lastSeq: $state->lastSeq,
                isStreaming: $state->isStreaming,
                streamingMessage: $state->streamingMessage,
                pendingToolCalls: $state->pendingToolCalls,
                errorMessage: $reason,
                messages: $state->messages,
                activeStepId: $state->activeStepId,
                retryableFailure: false,
            );
        }

        // continue: restore to Running from WaitingHuman/Failed
        if ('continue' === $kind) {
            $cmdPayload = \is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
            $isAutoRetry = true === ($cmdPayload['auto_retry'] ?? false);
            $retryAttempts = $isAutoRetry ? $state->retryAttempts : 0;

            return new RunState(
                runId: $state->runId,
                status: RunStatus::Running,
                version: $state->version,
                turnNo: $state->turnNo,
                lastSeq: $state->lastSeq,
                isStreaming: $state->isStreaming,
                streamingMessage: $state->streamingMessage,
                pendingToolCalls: $state->pendingToolCalls,
                errorMessage: null,
                messages: $state->messages,
                activeStepId: $state->activeStepId,
                retryableFailure: false,
                retryAttempts: $retryAttempts,
            );
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCommandRejected(array $payload, RunState $state): RunState
    {
        $reason = \is_string($payload['reason'] ?? null) ? $payload['reason'] : null;

        return new RunState(
            runId: $state->runId,
            status: $state->status,
            version: $state->version,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $reason ?? $state->errorMessage,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
            retryableFailure: $state->retryableFailure,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<AgentMessage>   $messages
     * @param array<string, bool>  $pendingToolCalls
     */
    private function applyLlmStepCompleted(array $payload, RunState $state, array &$messages, array &$pendingToolCalls): RunState
    {
        // Reset pending tool calls before processing the current step's calls.
        // This matches LlmStepResultHandler, which replaces pendingToolCalls
        // with the current assistant message's tool calls rather than
        // accumulating across steps.
        $pendingToolCalls = [];

        $assistantPayload = \is_array($payload['assistant_message'] ?? null) ? $payload['assistant_message'] : null;

        if (null !== $assistantPayload) {
            // Replay the assistant payload via a dedicated helper that
            // handles tool-call-only messages (content: null) which
            // AgentMessage::fromPayload() would reject.
            $msg = $this->replayAssistantMessage($assistantPayload);
            if (null !== $msg) {
                $messages[] = $msg;
            }

            // Track pending tool calls from the assistant message.
            $toolCalls = \is_array($assistantPayload['tool_calls'] ?? null) ? $assistantPayload['tool_calls'] : [];
            foreach ($toolCalls as $toolCall) {
                if (!\is_array($toolCall) || !\is_string($toolCall['id'] ?? null)) {
                    continue;
                }

                $pendingToolCalls[$toolCall['id']] = false;
            }
        }

        return new RunState(
            runId: $state->runId,
            status: RunStatus::Running,
            version: $state->version,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: null,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
            retryableFailure: false,
            retryAttempts: 0,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyLlmStepFailed(array $payload, RunState $state): RunState
    {
        $error = \is_array($payload['error'] ?? null) ? $payload['error'] : null;
        $errorMessage = \is_string($error['user_message'] ?? null)
            ? $error['user_message']
            : (\is_string($error['message'] ?? null) ? $error['message'] : 'LLM worker failed.');
        $retryable = \is_bool($payload['retryable'] ?? null) ? $payload['retryable'] : false;
        $retryAttempt = isset($payload['retry_attempt']) && is_numeric($payload['retry_attempt']) ? (int) $payload['retry_attempt'] : 0;

        return new RunState(
            runId: $state->runId,
            status: RunStatus::Failed,
            version: $state->version,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: $errorMessage,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
            retryableFailure: $retryable,
            retryAttempts: $retryAttempt,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, bool>  $pendingToolCalls
     */
    private function applyToolExecutionStart(array $payload, array &$pendingToolCalls, RunState $state): RunState
    {
        $toolCallId = \is_string($payload['tool_call_id'] ?? null) ? $payload['tool_call_id'] : null;

        if (null !== $toolCallId) {
            $pendingToolCalls[$toolCallId] = false;
        }

        return $state;
    }

    /**
     * Tool execution end resolves the matching pending tool call so that
     * standalone/shell tool calls (which do NOT go through the LLM step)
     * are properly marked as completed in the replayed RunState.
     *
     * Without this, tool_execution_end (seq N+1) leaves the pending tool
     * call from tool_execution_start (seq N) unresolved, and a subsequent
     * AdvanceRun (after the run completed) bails on the stale tool-call
     * guard even though the tool has already finished (issue #183).
     *
     * @param array<string, mixed> $payload
     * @param array<string, bool>  $pendingToolCalls
     */
    private function applyToolExecutionEnd(array $payload, array &$pendingToolCalls, RunState $state): RunState
    {
        $toolCallId = \is_string($payload['tool_call_id'] ?? null) ? $payload['tool_call_id'] : null;

        if (null !== $toolCallId) {
            $pendingToolCalls[$toolCallId] = true;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<AgentMessage>   $messages
     */
    private function applyMessageEnd(array $payload, RunState $state, array &$messages): RunState
    {
        $messageRole = \is_string($payload['message_role'] ?? null) ? $payload['message_role'] : null;

        // Tool messages: append the serialized tool result from the event.
        if ('tool' === $messageRole) {
            $messagePayload = \is_array($payload['message'] ?? null) ? $payload['message'] : null;

            if (null !== $messagePayload) {
                $msg = AgentMessage::fromPayload($messagePayload);
                if (null !== $msg) {
                    $messages[] = $msg;
                }
            }
        }

        return $state;
    }

    /**
     * @param array<string, bool> $pendingToolCalls
     */
    private function applyToolBatchCommitted(RunState $state, array &$pendingToolCalls): RunState
    {
        $pendingToolCalls = [];

        return $state;
    }

    private function applyWaitingHuman(RunState $state): RunState
    {
        return new RunState(
            runId: $state->runId,
            status: RunStatus::WaitingHuman,
            version: $state->version,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
            retryableFailure: $state->retryableFailure,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyAgentEnd(array $payload, RunState $state): RunState
    {
        $reason = \is_string($payload['reason'] ?? null) ? $payload['reason'] : null;

        $status = match ($reason) {
            'completed' => RunStatus::Completed,
            'cancelled' => RunStatus::Cancelled,
            default => RunStatus::Completed,
        };

        return new RunState(
            runId: $state->runId,
            status: $status,
            version: $state->version,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: $state->errorMessage,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
            retryableFailure: false,
        );
    }

    /**
     * Handle context_compaction_started: restore activeStepId from payload.step_id
     * so that a subsequent CompactionStepResult arriving after a state rebuild is
     * accepted by the result handler's staleness guard.
     *
     * Sets status to Compacting to mirror the live CompactRunHandler which
     * transitions the run into a dedicated compaction lifecycle.  This
     * prevents replay from building a state where a compaction is in flight
     * but the run appears Completed or Running, which would allow follow-up
     * commands to advance the run concurrently with the active compaction.
     *
     * Messages are not mutated — only activeStepId and status are restored.
     *
     * @param array<string, mixed> $payload
     */
    private function applyContextCompactionStarted(array $payload, RunState $state): RunState
    {
        $stepId = \is_string($payload['step_id'] ?? null) ? $payload['step_id'] : $state->activeStepId;

        return new RunState(
            runId: $state->runId,
            status: RunStatus::Compacting,
            version: $state->version,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $state->messages,
            activeStepId: $stepId,
            retryableFailure: $state->retryableFailure,
        );
    }

    /**
     * Handle context_compacted: replace messages from payload.messages
     * with the full compacted message list.  The by-ref $messages accumulator
     * is replaced wholesale, and later events (user/assistant/tool) append
     * on top of the compacted checkpoint.
     *
     * Clearing activeStepId mirrors the live CompactionStepResultHandler
     * which sets activeStepId: null on success — compaction is a one-shot
     * cycle and no AdvanceRun follows to reset the step.
     *
     * Resolves Compacting status based on trigger:
     * - auto → Running (the follow-up AdvanceRun effect continues the LLM turn)
     * - manual → Completed (the /compact command runs on an already-completed run)
     *
     * @param array<string, mixed> $payload
     * @param list<AgentMessage>   $messages
     */
    private function applyContextCompacted(array $payload, RunState $state, array &$messages): RunState
    {
        $rawMessages = \is_array($payload['messages'] ?? null) ? $payload['messages'] : [];

        // Replace the message accumulator with the compacted checkpoint.
        // Each entry is an AgentMessage::toArray() shape — replay
        // reconstructs via AgentMessage::fromPayload().
        $messages = [];

        foreach ($rawMessages as $rawMessage) {
            if (!\is_array($rawMessage)) {
                continue;
            }

            $msg = AgentMessage::fromPayload($rawMessage);
            if (null !== $msg) {
                $messages[] = $msg;
            }
        }

        $trigger = \is_string($payload['trigger'] ?? null) ? $payload['trigger'] : 'manual';
        $continueAfterCompaction = (bool) ($payload['continue_after_compaction'] ?? false);
        $finalStatus = $continueAfterCompaction ? RunStatus::Running : RunStatus::Completed;

        return new RunState(
            runId: $state->runId,
            status: $finalStatus,
            version: $state->version,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $state->messages,
            activeStepId: null,
            retryableFailure: $state->retryableFailure,
        );
    }

    /**
     * Handle context_compaction_failed: clears activeStepId and resolves
     * Compacting status to mirror the live handlers.
     *
     * Dual-emitter semantics:
     * - CompactRunHandler structural failures (before worker dispatch)
     *   have no step_id; they preserve activeStepId and prior status.
     * - CompactionStepResultHandler post-start failures include step_id
     *   and trigger.  When step_id matches the active step AND reason is
     *   not stale_result, the step is cleared and Compacting resolves:
     *   auto → Running, manual → Completed.
     * - stale_result: step_id matches but result is non-current.
     *   activeStepId is preserved (a newer in-flight compaction may be
     *   active).  If status is Compacting, resolve to Running so the
     *   state is not stuck in an unrecoverable terminal.
     * - step_id mismatch: the failure is for an old/crossed step.
     *   activeStepId is preserved.  If status is Compacting, resolve to
     *   Running.
     *
     * Messages are never mutated by context_compaction_failed.
     *
     * @param array<string, mixed> $payload
     */
    private function applyContextCompactionFailed(array $payload, RunState $state): RunState
    {
        $payloadStepId = \is_string($payload['step_id'] ?? null) ? $payload['step_id'] : null;
        $reason = \is_string($payload['reason'] ?? null) ? $payload['reason'] : null;
        $trigger = \is_string($payload['trigger'] ?? null) ? $payload['trigger'] : null;

        // Structural failures from CompactRunHandler have no step_id.
        // They happen before the worker is dispatched — preserve activeStepId
        // and prior status (no Compacting transition occurred in live handler).
        if (null === $payloadStepId) {
            return new RunState(
                runId: $state->runId,
                status: $state->status,
                version: $state->version,
                turnNo: $state->turnNo,
                lastSeq: $state->lastSeq,
                isStreaming: $state->isStreaming,
                streamingMessage: $state->streamingMessage,
                pendingToolCalls: $state->pendingToolCalls,
                errorMessage: $state->errorMessage,
                messages: $state->messages,
                activeStepId: $state->activeStepId,
                retryableFailure: $state->retryableFailure,
            );
        }

        // Resolve Compacting status: the terminal failure event ends the
        // compaction lifecycle.
        // - Step_id matches AND not stale: terminal resolution — use
        //   continue_after_compaction flag to distinguish pre-LLM guard
        //   failures (stay Running so turn can proceed) from maintenance
        //   failures (return to Completed).
        // - stale_result or step_id mismatch: always resolve to Running.
        //   The live handler treats stale as non-current without looking at
        //   trigger; mismatch means a newer compaction is in flight.
        $continueAfterCompaction = (bool) ($payload['continue_after_compaction'] ?? false);
        $isTerminal = $payloadStepId === $state->activeStepId && 'stale_result' !== $reason;
        $resolveCompacting = RunStatus::Compacting === $state->status
            ? ($isTerminal && !$continueAfterCompaction ? RunStatus::Completed : RunStatus::Running)
            : null;

        // Step_id matches AND not stale → clear the step (compaction
        // lifecycle complete).  Resolve Compacting if applicable.
        if ($isTerminal) {
            return new RunState(
                runId: $state->runId,
                status: $resolveCompacting ?? $state->status,
                version: $state->version,
                turnNo: $state->turnNo,
                lastSeq: $state->lastSeq,
                isStreaming: $state->isStreaming,
                streamingMessage: $state->streamingMessage,
                pendingToolCalls: $state->pendingToolCalls,
                errorMessage: $state->errorMessage,
                messages: $state->messages,
                activeStepId: null,
                retryableFailure: $state->retryableFailure,
            );
        }

        // Step_id mismatch OR stale_result: preserve activeStepId.
        // Resolve Compacting to Running if stuck (stale or crossed step
        // arrived while a newer compaction may be in flight).  The newer
        // compaction's own started event will set Compacting again.
        return new RunState(
            runId: $state->runId,
            status: $resolveCompacting ?? $state->status,
            version: $state->version,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
            retryableFailure: $state->retryableFailure,
        );
    }

    private function applyNoMutation(RunEvent $event, RunState $state): RunState
    {
        return $state;
    }

    // ── Integrity helpers ───────────────────────────────────────────────────

    /**
     * Identifies duplicate event sequence numbers.
     *
     * @param list<RunEvent> $events sorted ascending by seq
     *
     * @return list<int> duplicate sequence numbers
     */
    private function duplicateSequences(array $events): array
    {
        $duplicates = [];
        $seen = [];

        foreach ($events as $event) {
            if (\in_array($event->seq, $seen, true)) {
                $duplicates[] = $event->seq;
            } else {
                $seen[] = $event->seq;
            }
        }

        return $duplicates;
    }

    /**
     * Orders events by their sequence number in ascending order.
     *
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    private function sortBySequence(array $events): array
    {
        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return $events;
    }

    /**
     * Identifies gaps in event sequences.
     *
     * @param list<RunEvent> $events sorted ascending by seq
     *
     * @return list<int>
     */
    private function missingSequences(array $events): array
    {
        $missing = [];
        $expected = 1;

        foreach ($events as $event) {
            if ($event->seq < $expected) {
                continue;
            }

            while ($expected < $event->seq) {
                $missing[] = $expected;
                ++$expected;
            }

            ++$expected;
        }

        return $missing;
    }

    /**
     * Replays an assistant message payload from the canonical event stream.
     *
     * Differs from {@see AgentMessage::fromPayload()} in one key aspect:
     * when the real {@see AgentMessageNormalizer::assistantMessagePayload()}
     * produces content:null for a tool-call-only assistant response,
     * {@see AgentMessage::fromPayload()} rejects the payload (it requires
     * content to be an array).  This helper detects that case and constructs
     * an {@see AgentMessage} with empty content and tool calls placed in
     * metadata, matching the semantics of
     * {@see AgentMessageNormalizer::assistantMessage()}.
     *
     * @param array<string, mixed> $payload
     */
    private function replayAssistantMessage(array $payload): ?AgentMessage
    {
        $msg = AgentMessage::fromPayload($payload);

        // fromPayload succeeded — standard path for text-bearing messages.
        if (null !== $msg) {
            return $msg;
        }

        // Only handle assistant-role payloads where content is null/missing.
        // fromPayload rejects these because is_array(content) fails, but
        // the real AgentMessageNormalizer produces this shape for
        // tool-call-only assistant responses.
        $role = $payload['role'] ?? null;

        if ('assistant' !== $role) {
            return null;
        }

        $metadata = [];
        $rawToolCalls = \is_array($payload['tool_calls'] ?? null) ? $payload['tool_calls'] : [];
        if ([] !== $rawToolCalls) {
            $metadata['tool_calls'] = $rawToolCalls;
        }

        $details = \is_array($payload['details'] ?? null) && [] !== $payload['details']
            ? $payload['details']
            : null;

        // Filter thinking-only assistant messages (no content, no tool
        // calls, reasoning present in details). These were erroneously
        // persisted from provider reasoning-only responses (e.g. DeepSeek
        // when max_tokens is exhausted mid-thinking) and cannot be
        // replayed as valid conversation turns — providers reject
        // {content: null, reasoning_content: "..."}.
        if ([] === $rawToolCalls
            && null !== $details
            && \is_string($details['thinking'] ?? null)
        ) {
            return null;
        }

        return new AgentMessage(
            role: 'assistant',
            content: [],
            details: $details,
            metadata: $metadata,
        );
    }

    /**
     * @param list<RunEvent> $events
     */
    private function maxSequence(array $events): int
    {
        if ([] === $events) {
            return 0;
        }

        return (int) max(array_map(static fn (RunEvent $event): int => $event->seq, $events));
    }
}
