<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Replay;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\CurrentOperationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;

final readonly class RunStateReducer
{
    /**
     * @param list<RunEvent> $events
     */
    public function replay(RunState $existingState, array $events): RunState
    {
        $state = new RunState(
            runId: $existingState->runId,
            status: RunStatus::Queued,
            version: $existingState->version,
            turnNo: 0,
            lastSeq: 0,
            pendingHumanInputRequests: $existingState->pendingHumanInputRequests,
            model: $existingState->model,
        );

        // By-ref accumulators: reducers append to these arrays; intermediate
        // RunState objects carry stale copies (see docblock invariant above).
        $messages = [];
        $pendingToolCalls = [];

        foreach ($events as $event) {
            $state = $this->applyEvent($state, $event, $messages, $pendingToolCalls);

            // Advance lastSeq to the current event's sequence number.
            $state = $state->with(['lastSeq' => $event->seq]);
        }

        // Copy mutable collections back into a new RunState with final values.
        return $state->with([
            'isStreaming' => false,
            'streamingMessage' => null,
            'pendingToolCalls' => $pendingToolCalls,
            'messages' => $messages,
        ]);
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

        $nextState = match ($event->type) {
            RunEventTypeEnum::RunStarted->value => $this->applyRunStarted($event, $state, $messages),
            RunEventTypeEnum::ModelChanged->value => $this->applyModelChanged($payload, $state),
            RunEventTypeEnum::TurnAdvanced->value => $this->applyTurnAdvanced($payload, $state),
            RunEventTypeEnum::AgentCommandApplied->value => $this->applyAgentCommandApplied($payload, $state, $messages),
            RunEventTypeEnum::AgentCommandRejected->value => $this->applyCommandRejected($payload, $state),
            RunEventTypeEnum::LlmStepCompleted->value => $this->applyLlmStepCompleted($payload, $state, $messages, $pendingToolCalls),
            RunEventTypeEnum::LlmStepFailed->value => $this->applyLlmStepFailed($payload, $state),
            RunEventTypeEnum::LlmStepAborted->value => $state->with(['currentOperation' => null]),
            RunEventTypeEnum::ToolExecutionStart->value => $this->applyToolExecutionStart($payload, $pendingToolCalls, $state),
            RunEventTypeEnum::ToolExecutionEnd->value => $this->applyToolExecutionEnd($payload, $pendingToolCalls, $state),
            RunEventTypeEnum::ToolCallResultReceived->value => $this->applyNoMutation($event, $state),
            RunEventTypeEnum::MessageStart->value => $this->applyNoMutation($event, $state),
            RunEventTypeEnum::MessageEnd->value => $this->applyMessageEnd($payload, $state, $messages),
            RunEventTypeEnum::ToolBatchCommitted->value => $this->applyToolBatchCommitted($state, $pendingToolCalls),
            RunEventTypeEnum::WaitingHuman->value => $this->applyWaitingHuman($event->payload, $state),
            RunEventTypeEnum::AgentEnd->value => $this->applyAgentEnd($payload, $state),
            RunEventTypeEnum::AgentStart->value,
            RunEventTypeEnum::TurnStart->value,
            RunEventTypeEnum::MessageUpdate->value,
            RunEventTypeEnum::ToolExecutionUpdate->value,
            RunEventTypeEnum::TurnEnd->value,
            RunEventTypeEnum::AgentCommandQueued->value,
            RunEventTypeEnum::AgentCommandSuperseded->value,
            RunEventTypeEnum::StaleResultIgnored->value => $this->applyNoMutation($event, $state),
            RunEventTypeEnum::ContextCompactionRequested->value => $this->applyNoMutation($event, $state),
            RunEventTypeEnum::ContextCompactionStarted->value => $this->applyContextCompactionStarted($payload, $state),
            RunEventTypeEnum::ContextCompacted->value => $this->applyContextCompacted($payload, $state, $messages),
            RunEventTypeEnum::ContextCompactionFailed->value => $this->applyContextCompactionFailed($payload, $state),
            RunEventTypeEnum::HistoryPositionSet->value,
            RunEventTypeEnum::HistoryTailDiscarded->value => $this->applyNoMutation($event, $state),
            default => $this->applyNoMutation($event, $state),
        };

        $advanceKey = $payload['advance_idempotency_key'] ?? null;

        return \is_string($advanceKey) && '' !== $advanceKey
            ? $nextState->with(['lastAppliedAdvanceKey' => $advanceKey])
            : $nextState;
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

        $model = null;
        $rawMetadata = $innerPayload['metadata'] ?? null;
        if (\is_array($rawMetadata)) {
            $rawModel = $rawMetadata['model'] ?? null;
            if (\is_string($rawModel)) {
                $rawModel = trim($rawModel);
                $model = '' !== $rawModel ? $rawModel : null;
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
            pendingHumanInputRequests: $state->pendingHumanInputRequests,
            model: $model,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyModelChanged(array $payload, RunState $state): RunState
    {
        $model = $payload['model'] ?? null;
        if (!\is_string($model)) {
            return $state;
        }
        $model = trim($model);
        if ('' === $model) {
            return $state;
        }

        return $state->with(['model' => $model]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyTurnAdvanced(array $payload, RunState $state): RunState
    {
        $turnNo = \is_int($payload['turn_no'] ?? null) ? $payload['turn_no'] : $state->turnNo;
        $stepId = \is_string($payload['step_id'] ?? null) ? $payload['step_id'] : $state->activeStepId;

        $attempt = \is_int($payload['operation_attempt'] ?? null) ? $payload['operation_attempt'] : 1;
        $key = \is_string($payload['operation_idempotency_key'] ?? null)
            ? $payload['operation_idempotency_key']
            : hash('sha256', \sprintf('%s|llm|%d|%s', $state->runId, $turnNo, $stepId));

        return $state->with([
            'status' => RunStatus::Running,
            'turnNo' => $turnNo,
            'errorMessage' => null,
            'activeStepId' => $stepId,
            'currentOperation' => new CurrentOperationDTO(CurrentOperationKindEnum::Llm, $turnNo, $stepId, $attempt, $key),
            'retryableFailure' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<AgentMessage>   $messages
     */
    private function applyAgentCommandApplied(array $payload, RunState $state, array &$messages): RunState
    {
        $kind = \is_string($payload['kind'] ?? null) ? $payload['kind'] : null;

        if ('shell_command' === $kind) {
            $key = \is_string($payload['idempotency_key'] ?? null) ? $payload['idempotency_key'] : null;
            if (null === $key || '' === $key) {
                return $state;
            }

            $toolCallId = 'sh_'.hash('sha256', $key);

            return $state->with([
                'pendingShellToolCalls' => [...$state->pendingShellToolCalls, $toolCallId => true],
            ]);
        }

        // steer / follow_up / append_message: append message to prompt context
        if (\in_array($kind, ['steer', 'follow_up', 'append_message'], true)) {
            $messagePayload = \is_array($payload['message'] ?? null) ? $payload['message'] : null;
            if (null !== $messagePayload) {
                $msg = AgentMessage::fromPayload($messagePayload);
                if (null !== $msg) {
                    $messages[] = $msg;
                }
            }

            return $state->with([
                'status' => RunStatus::Running,
                'errorMessage' => null,
                'retryableFailure' => false,
            ]);
        }

        // human_response: clear only the active matching request.
        // ModelTurn may append a human message; ToolCall has no model-visible message.
        // Status stays WaitingHuman while more pending requests remain.
        if ('human_response' === $kind) {
            $messagePayload = \is_array($payload['message'] ?? null) ? $payload['message'] : null;
            if (null !== $messagePayload) {
                $msg = AgentMessage::fromPayload($messagePayload);
                if (null !== $msg) {
                    $messages[] = $msg;
                }
            }

            $questionId = \is_string($payload['question_id'] ?? null) ? $payload['question_id'] : null;
            if (null === $questionId || '' === $questionId) {
                throw new \InvalidArgumentException('human_response event is missing non-empty question_id.');
            }

            $active = $state->pendingHumanInputRequests[0] ?? null;
            if (null === $active || $active->questionId !== $questionId) {
                throw new \InvalidArgumentException(\sprintf('human_response event question_id "%s" does not match the active pending request.', $questionId));
            }

            $remaining = array_values(\array_slice($state->pendingHumanInputRequests, 1));

            // Intermediate RunState.messages stay stale; final replay() copies the
            // by-ref $messages accumulator (ModelTurn may have appended above).
            return $state->with([
                'status' => [] !== $remaining ? RunStatus::WaitingHuman : RunStatus::Running,
                'errorMessage' => null,
                'retryableFailure' => false,
                'retryAttempts' => 0,
                'pendingHumanInputRequests' => $remaining,
            ]);
        }

        // cancel: transition to Cancelling
        if ('cancel' === $kind) {
            $reason = \is_string($payload['reason'] ?? null) ? $payload['reason'] : null;

            return $state->with([
                'status' => RunStatus::Cancelling,
                'errorMessage' => $reason,
                'retryableFailure' => false,
                'retryAttempts' => 0,
            ]);
        }

        // continue: restore to Running from WaitingHuman/Failed
        if ('continue' === $kind) {
            $cmdPayload = \is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
            $isAutoRetry = true === ($cmdPayload['auto_retry'] ?? false);
            $retryAttempts = $isAutoRetry ? $state->retryAttempts : 0;

            return $state->with([
                'status' => RunStatus::Running,
                'errorMessage' => null,
                'retryableFailure' => false,
                'retryAttempts' => $retryAttempts,
            ]);
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCommandRejected(array $payload, RunState $state): RunState
    {
        $reason = \is_string($payload['reason'] ?? null) ? $payload['reason'] : null;

        return $state->with(['errorMessage' => $reason ?? $state->errorMessage]);
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

        return $state->with([
            'status' => RunStatus::Running,
            'errorMessage' => null,
            'retryableFailure' => false,
            'retryAttempts' => 0,
            'currentOperation' => null,
        ]);
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

        return $state->with([
            'status' => RunStatus::Failed,
            'isStreaming' => false,
            'streamingMessage' => null,
            'pendingToolCalls' => [],
            'currentOperation' => null,
            'errorMessage' => $errorMessage,
            'retryableFailure' => $retryable,
            'retryAttempts' => $retryAttempt,
        ]);
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
            if (isset($state->pendingShellToolCalls[$toolCallId])) {
                $pendingShellToolCalls = $state->pendingShellToolCalls;
                unset($pendingShellToolCalls[$toolCallId]);

                return $state->with(['pendingShellToolCalls' => $pendingShellToolCalls]);
            }
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

    /**
     * @param array<string, mixed> $payload
     */
    private function applyWaitingHuman(array $payload, RunState $state): RunState
    {
        // Malformed waiting_human must fail at the reducer boundary — never enter
        // WaitingHuman without a reconstructable typed pending request.
        // Tool-call suspensions embed continuation_kind=tool_call + continuation_ref.
        $continuationKind = $payload['continuation_kind'] ?? null;
        if ('tool_call' === $continuationKind) {
            $continuationRef = \is_array($payload['continuation_ref'] ?? null)
                ? $payload['continuation_ref']
                : [];
            $request = PendingHumanInputRequestDTO::toolCallFromPayload($payload, $continuationRef);
        } else {
            $request = PendingHumanInputRequestDTO::modelTurnFromInterruptPayload($payload);
        }

        return $state->with([
            'status' => RunStatus::WaitingHuman,
            'pendingHumanInputRequests' => [...$state->pendingHumanInputRequests, $request],
        ]);
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

        return $state->with([
            'status' => $status,
            'isStreaming' => false,
            'streamingMessage' => null,
            'pendingToolCalls' => [],
            'pendingShellToolCalls' => [],
            'activeStepId' => null,
            'currentOperation' => null,
            'retryableFailure' => false,
            'retryAttempts' => 0,
        ]);
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

        $attempt = \is_int($payload['operation_attempt'] ?? null) ? $payload['operation_attempt'] : 1;
        $key = \is_string($payload['operation_idempotency_key'] ?? null) ? $payload['operation_idempotency_key'] : null;

        return $state->with([
            'status' => RunStatus::Compacting,
            'activeStepId' => $stepId,
            'currentOperation' => null !== $stepId && null !== $key
                ? new CurrentOperationDTO(CurrentOperationKindEnum::Compaction, $state->turnNo, $stepId, $attempt, $key)
                : null,
            'retryAttempts' => 0,
        ]);
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

        return $state->with([
            'status' => $finalStatus,
            'activeStepId' => null,
            'currentOperation' => null,
            'lastAppliedCompactionKey' => $state->currentOperation->idempotencyKey,
            'retryAttempts' => 0,
        ]);
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
            return $state->with(['retryAttempts' => 0]);
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
            return $state->with([
                'status' => $resolveCompacting ?? $state->status,
                'activeStepId' => null,
                'currentOperation' => null,
                'lastAppliedCompactionKey' => $state->currentOperation->idempotencyKey,
                'retryAttempts' => 0,
            ]);
        }

        // Step_id mismatch OR stale_result: preserve activeStepId.
        // Resolve Compacting to Running if stuck (stale or crossed step
        // arrived while a newer compaction may be in flight).  The newer
        // compaction's own started event will set Compacting again.
        return $state->with([
            'status' => $resolveCompacting ?? $state->status,
            'retryAttempts' => 0,
        ]);
    }

    private function applyNoMutation(RunEvent $event, RunState $state): RunState
    {
        return $state;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function replayAssistantMessage(array $payload): ?AgentMessage
    {
        $msg = AgentMessage::fromPayload($payload);

        // fromPayload succeeded — standard path for text-bearing messages.
        if (null !== $msg) {
            return $this->withReplayedAssistantToolCalls($msg, $payload);
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
     * Canonical llm_step_completed assistant payloads store tool_calls at the
     * top level (see AgentMessageNormalizer::assistantMessagePayload()).
     * AgentMessage::fromPayload() only reads metadata.tool_calls, so text-bearing
     * assistant messages must copy top-level tool_calls into metadata on replay.
     *
     * @param array<string, mixed> $payload
     */
    private function withReplayedAssistantToolCalls(AgentMessage $message, array $payload): AgentMessage
    {
        $rawToolCalls = \is_array($payload['tool_calls'] ?? null) ? $payload['tool_calls'] : [];
        if ([] === $rawToolCalls) {
            return $message;
        }

        $metadata = $message->metadata;
        $metadata['tool_calls'] = $rawToolCalls;

        return new AgentMessage(
            role: $message->role,
            content: $message->content,
            timestamp: $message->timestamp,
            name: $message->name,
            toolCallId: $message->toolCallId,
            toolName: $message->toolName,
            details: $message->details,
            isError: $message->isError,
            metadata: $metadata,
        );
    }
}
