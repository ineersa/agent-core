<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles {@see CompactionStepResult} messages from async compaction workers.
 *
 * Validates staleness, processes the summary text through the compaction
 * service, and emits either context_compacted (replacing messages) or
 * context_compaction_failed (preserving messages).
 *
 * Lives in CodingAgent because it depends on CompactionServiceInterface
 * for building the compacted message list.
 */
final class CompactionStepResultHandler implements RunMessageHandler
{
    public function __construct(
        private CompactionServiceInterface $compactionService,
        private EventFactory $eventFactory,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof CompactionStepResult;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof CompactionStepResult) {
            throw new \InvalidArgumentException('CompactionStepResultHandler can only handle CompactionStepResult messages.');
        }

        $runId = $message->runId();

        // Guard: if the turn number no longer matches or the stepId no longer
        // matches the active step, the result is stale.  Emit a terminal
        // context_compaction_failed event so the user-visible compaction
        // state is resolved instead of leaving a dangling started event.
        //
        // Preserve the current activeStepId — clearing it would lose a
        // newer in-flight compaction's identity (e.g. compaction B started,
        // stale result A arrives).  The active step is only cleared when
        // the result genuinely matches the current step (success/error paths).
        //
        // NOTE: run status alone is NOT a staleness signal.  Manual compaction
        // is commonly triggered on a Completed run — CompactRunHandler sets
        // activeStepId while the run stays Completed, and the matching async
        // result must be accepted.  Correlation (turnNo + stepId/activeStepId)
        // is the true staleness guard: turnNo advances on new conversation
        // turns, and activeStepId changes when a newer compaction starts.
        if ($state->turnNo !== $message->turnNo() || $state->activeStepId !== $message->stepId()) {
            $this->logger->info('Compaction result is stale — discarding.', [
                'session_id' => $runId,
                'component' => 'compaction',
                'event_type' => 'compaction.stale_result',
                'run_id' => $runId,
                'step_id' => $message->stepId(),
                'state_turn_no' => $state->turnNo,
                'result_turn_no' => $message->turnNo(),
                'active_step_id' => $state->activeStepId,
            ]);

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, [[
                'type' => RunEventTypeEnum::ContextCompactionFailed->value,
                'payload' => [
                    'reason' => 'stale_result',
                    'message' => 'Compaction result arrived too late — the active step has moved on.',
                    'messages_replaced' => false,
                    'step_id' => $message->stepId(),
                    'trigger' => $message->trigger,
                ],
            ]]);

            // Stale result — resolve Compacting back to a running state.
            // If a newer compaction IS active (different activeStepId), its
            // handler will set Compacting again on its own started event.
            $staleFinalStatus = RunStatus::Compacting === $state->status
                ? RunStatus::Running
                : $state->status;

            return new HandlerResult(
                nextState: $this->incrementState($state, $events, clearActiveStepId: false, status: $staleFinalStatus),
                events: $events,
            );
        }

        // Error from model invocation → emit failure, preserve messages.
        // Prefer the sanitised user_message from the error classifier
        // (LlmPlatformAdapter::errorResult() → LlmProviderErrorClassifier)
        // to avoid surfacing raw provider exception text in the TUI.
        // The raw message is still stored for diagnostics/logging.
        if (null !== $message->error) {
            $this->logger->error('Compaction model invocation failed.', [
                'session_id' => $runId,
                'component' => 'compaction',
                'event_type' => 'compaction.failed',
                'run_id' => $runId,
                'step_id' => $message->stepId(),
                'error_code' => $message->error['code'] ?? 'unknown',
                'retryable' => (bool) ($message->error['retryable'] ?? false),
                // Do NOT log raw error message — may contain provider URLs.
                'has_user_message' => isset($message->error['user_message']) && '' !== $message->error['user_message'],
            ]);
            $reason = 'model_error';
            $userMessage = \is_string($message->error['user_message'] ?? null) && '' !== $message->error['user_message']
                ? $message->error['user_message']
                : (\is_string($message->error['message'] ?? null) && '' !== $message->error['message']
                    ? $message->error['message']
                    : 'Summarization model call failed.');

            // Resolve Compacting status: when the compaction was holding
            // a pending LLM turn (continueAfterCompaction, pre-LLM guard),
            // the run stays Running so the turn can proceed.  Maintenance
            // compaction (after-turn hook, manual) returns to terminal.
            //
            // PRESERVE Cancelling: if cancel was accepted during
            // compaction, the incoming state is Cancelling and must not
            // be overwritten.  The active step is cleared (no more work),
            // so transition to Cancelled without emitting AdvanceRun.
            $errorFinalStatus = match (true) {
                RunStatus::Cancelling === $state->status => RunStatus::Cancelled,
                $message->continueAfterCompaction => RunStatus::Running,
                default => RunStatus::Completed,
            };

            // When a pending LLM turn was held open (pre-LLM guard) and
            // compaction failed, dispatch AdvanceRun so the pending turn
            // proceeds on the original (uncompacted) messages.  The
            // pre-LLM guard's turn-level dedup prevents immediate re-fire.
            // Do NOT advance when Cancelling — the run has been cancelled
            // and the Cancelled resolution above is terminal.
            $errorEffects = [];
            if (RunStatus::Cancelling !== $state->status && $message->continueAfterCompaction) {
                $continueStepId = \sprintf('advance-%d', hrtime(true));
                $errorEffects[] = new AdvanceRun(
                    runId: $runId,
                    turnNo: $state->turnNo,
                    stepId: $continueStepId,
                    attempt: 1,
                    idempotencyKey: hash('sha256', \sprintf('%s|advance|%d|%s', $runId, $state->turnNo, $continueStepId)),
                );
            }

            // Build event specs: context_compaction_failed FIRST, then
            // optional agent_end LAST so terminal Cancelled wins in replay.
            $errorSpecs = [[
                'type' => RunEventTypeEnum::ContextCompactionFailed->value,
                'payload' => [
                    'reason' => $reason,
                    'message' => $userMessage,
                    'messages_replaced' => false,
                    'step_id' => $message->stepId(),
                    'model' => $message->model,
                    'thinking_level' => $message->modelOptions['thinking_level'] ?? null,
                    'trigger' => $message->trigger,
                    'continue_after_compaction' => $message->continueAfterCompaction,
                ],
            ]];

            if (RunStatus::Cancelling === $state->status) {
                $errorSpecs[] = [
                    'type' => RunEventTypeEnum::AgentEnd->value,
                    'payload' => ['reason' => 'cancelled'],
                ];
            }

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $errorSpecs);

            return new HandlerResult(
                nextState: $this->incrementState($state, $events, clearActiveStepId: true, status: $errorFinalStatus),
                events: $events,
                effects: $errorEffects,
            );
        }

        // Empty or whitespace-only summary → emit failure, preserve messages.
        $summaryText = \is_string($message->summaryText) ? trim($message->summaryText) : '';

        if ('' === $summaryText) {
            $this->logger->info('Compaction produced an empty summary.', [
                'session_id' => $runId,
                'component' => 'compaction',
                'event_type' => 'compaction.failed',
                'run_id' => $runId,
                'reason' => 'empty_summary',
                'step_id' => $message->stepId(),
            ]);
            // Resolve Compacting status: same policy as model_error path.
            // Preserve Cancelling → Cancelled when no more work remains.
            $emptyFinalStatus = match (true) {
                RunStatus::Cancelling === $state->status => RunStatus::Cancelled,
                $message->continueAfterCompaction => RunStatus::Running,
                default => RunStatus::Completed,
            };

            // Same continuation policy as model_error path: when a pending
            // LLM turn was held open and compaction failed on empty summary,
            // dispatch AdvanceRun so the turn proceeds on original messages.
            // Do NOT advance when Cancelling.
            $emptyEffects = [];
            if (RunStatus::Cancelling !== $state->status && $message->continueAfterCompaction) {
                $continueStepId = \sprintf('advance-%d', hrtime(true));
                $emptyEffects[] = new AdvanceRun(
                    runId: $runId,
                    turnNo: $state->turnNo,
                    stepId: $continueStepId,
                    attempt: 1,
                    idempotencyKey: hash('sha256', \sprintf('%s|advance|%d|%s', $runId, $state->turnNo, $continueStepId)),
                );
            }

            // Build event specs: context_compaction_failed FIRST, then
            // optional agent_end LAST so terminal Cancelled wins in replay.
            $emptySpecs = [[
                'type' => RunEventTypeEnum::ContextCompactionFailed->value,
                'payload' => [
                    'reason' => 'empty_summary',
                    'message' => 'Compaction failed: summarization model returned an empty summary.',
                    'messages_replaced' => false,
                    'step_id' => $message->stepId(),
                    'model' => $message->model,
                    'thinking_level' => $message->modelOptions['thinking_level'] ?? null,
                    'trigger' => $message->trigger,
                    'continue_after_compaction' => $message->continueAfterCompaction,
                ],
            ]];

            if (RunStatus::Cancelling === $state->status) {
                $emptySpecs[] = [
                    'type' => RunEventTypeEnum::AgentEnd->value,
                    'payload' => ['reason' => 'cancelled'],
                ];
            }

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $emptySpecs);

            return new HandlerResult(
                nextState: $this->incrementState($state, $events, clearActiveStepId: true, status: $emptyFinalStatus),
                events: $events,
                effects: $emptyEffects,
            );
        }

        // Success: build compacted messages and replace RunState.messages.

        $this->logger->info('Compaction applied successfully.', [
            'session_id' => $runId,
            'component' => 'compaction',
            'event_type' => 'compaction.applied',
            'run_id' => $runId,
            'step_id' => $message->stepId(),
            'summary_length' => \strlen($summaryText),
            'messages_compacted' => $message->messagesCompacted,
            'messages_retained' => $message->messagesRetained,
        ]);
        // Deserialize retained tail messages from transport-safe array shapes.
        $retainedTail = [];
        foreach ($message->retainedTailMessages as $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            $msg = AgentMessage::fromPayload($raw);
            if (null !== $msg) {
                $retainedTail[] = $msg;
            }
        }

        // priorSummaryPresent is synthetic: the summarization prompt already
        // handled the prior compact_summary marker when it was present.
        // buildCompactedMessages only uses it to annotate the new summary
        // message — it does not affect the merged message list.
        $preparation = CompactionPrepareResult::ready(
            messagesToSummarize: [], // Not needed for buildCompactedMessages
            retainedTailMessages: $retainedTail,
            tokenEstimateBefore: $message->tokenEstimateBefore,
            messagesCompacted: $message->messagesCompacted,
            messagesRetained: $message->messagesRetained,
            firstRetainedIndex: $message->firstRetainedIndex,
            priorSummaryPresent: false,
        );

        $compactResult = $this->compactionService->buildCompactedMessages(
            $summaryText,
            $preparation,
        );

        // Guard: ineffective compaction — the token estimate did not
        // decrease (or even increased).  The summarization model
        // produced a summary whose overhead exceeded the savings.
        //
        // Session 13 evidence: two auto compactions produced zero
        // token reduction (27316→27464 and 27528→27528) — the handler
        // replaced messages and emitted context_compacted for a useless
        // compaction.
        //
        // Emit context_compaction_failed with reason ineffective_compaction,
        // preserve original messages, and include before/after estimates
        // for diagnostics.  Same continuation/cancelling policy as other
        // failure paths.
        if ($compactResult->tokenEstimateAfter >= $compactResult->tokenEstimateBefore) {
            $this->logger->info('Compaction was ineffective — token estimate did not decrease.', [
                'session_id' => $runId,
                'component' => 'compaction',
                'event_type' => 'compaction.ineffective',
                'run_id' => $runId,
                'step_id' => $message->stepId(),
                'estimated_tokens_before' => $compactResult->tokenEstimateBefore,
                'estimated_tokens_after' => $compactResult->tokenEstimateAfter,
                'messages_compacted' => $compactResult->messagesCompacted,
                'messages_retained' => $compactResult->messagesRetained,
            ]);

            $ineffectiveFinalStatus = match (true) {
                RunStatus::Cancelling === $state->status => RunStatus::Cancelled,
                $message->continueAfterCompaction => RunStatus::Running,
                default => RunStatus::Completed,
            };

            $ineffectiveEffects = [];
            if (RunStatus::Cancelling !== $state->status && $message->continueAfterCompaction) {
                $continueStepId = \sprintf('advance-%d', hrtime(true));
                $ineffectiveEffects[] = new AdvanceRun(
                    runId: $runId,
                    turnNo: $state->turnNo,
                    stepId: $continueStepId,
                    attempt: 1,
                    idempotencyKey: hash('sha256', \sprintf('%s|advance|%d|%s', $runId, $state->turnNo, $continueStepId)),
                );
            }

            $ineffectiveSpecs = [[
                'type' => RunEventTypeEnum::ContextCompactionFailed->value,
                'payload' => [
                    'reason' => 'ineffective_compaction',
                    'message' => 'Compaction did not reduce context size — messages were preserved.',
                    'messages_replaced' => false,
                    'step_id' => $message->stepId(),
                    'model' => $message->model,
                    'thinking_level' => $message->modelOptions['thinking_level'] ?? null,
                    'trigger' => $message->trigger,
                    'continue_after_compaction' => $message->continueAfterCompaction,
                    'estimated_tokens_before' => $compactResult->tokenEstimateBefore,
                    'estimated_tokens_after' => $compactResult->tokenEstimateAfter,
                    'messages_compacted' => $compactResult->messagesCompacted,
                    'messages_retained' => $compactResult->messagesRetained,
                ],
            ]];

            if (RunStatus::Cancelling === $state->status) {
                $ineffectiveSpecs[] = [
                    'type' => RunEventTypeEnum::AgentEnd->value,
                    'payload' => ['reason' => 'cancelled'],
                ];
            }

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $ineffectiveSpecs);

            return new HandlerResult(
                nextState: $this->incrementState($state, $events, clearActiveStepId: true, status: $ineffectiveFinalStatus),
                events: $events,
                effects: $ineffectiveEffects,
            );
        }

        // Serialize compacted messages to array representation for the
        // context_compacted payload. The replay path reconstructs
        // AgentMessage instances from toArray() output via AgentMessage::fromPayload().
        $serializedMessages = array_map(
            static fn (AgentMessage $msg): array => $msg->toArray(),
            $compactResult->compactedMessages,
        );

        // Resolve Compacting based on continuation intent, NOT trigger.
        // - continueAfterCompaction: the compaction was holding a pending LLM
        //   turn (pre-LLM guard path) → Running so the turn can continue.
        // - maintenance (after-turn hook, manual): the run was already
        //   terminal before compaction → Completed.
        // - Cancelling: user cancelled while compaction was in flight.
        //   Cancellation always wins → Cancelled with NO AdvanceRun.
        $finalStatus = match (true) {
            RunStatus::Cancelling === $state->status => RunStatus::Cancelled,
            $message->continueAfterCompaction => RunStatus::Running,
            default => RunStatus::Completed,
        };

        // Build event specs: context_compacted FIRST, then optional
        // agent_end LAST so terminal Cancelled wins in replay.
        $successSpecs = [[
            'type' => RunEventTypeEnum::ContextCompacted->value,
            'payload' => [
                'summary_text' => $summaryText,
                'messages' => $serializedMessages,
                'estimated_tokens_before' => $compactResult->tokenEstimateBefore,
                'estimated_tokens_after' => $compactResult->tokenEstimateAfter,
                'messages_compacted' => $compactResult->messagesCompacted,
                'messages_retained' => $compactResult->messagesRetained,
                'first_retained_index' => $compactResult->firstRetainedIndex,
                'model' => $message->model,
                'thinking_level' => $message->modelOptions['thinking_level'] ?? null,
                'trigger' => $message->trigger,
                'continue_after_compaction' => $message->continueAfterCompaction,
                'hook_metadata' => $message->hookMetadata,
            ],
        ]];

        if (RunStatus::Cancelling === $state->status) {
            $successSpecs[] = [
                'type' => RunEventTypeEnum::AgentEnd->value,
                'payload' => ['reason' => 'cancelled'],
            ];
        }

        $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $successSpecs);

        // Atomically replace RunState.messages with the compacted list.
        $nextState = new RunState(
            runId: $state->runId,
            status: $finalStatus,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + \count($events),
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $compactResult->compactedMessages,
            activeStepId: null,
            retryableFailure: $state->retryableFailure,
        );

        // Continue the LLM turn ONLY when the compaction was holding a
        // pending turn open (pre-LLM guard path) AND cancellation has NOT
        // been requested.  After-turn maintenance and manual /compact must
        // NOT auto-continue — the run is already terminal and the user is
        // expected to follow-up manually.
        $effects = [];
        if (RunStatus::Cancelling !== $state->status && $message->continueAfterCompaction) {
            $continueStepId = \sprintf('advance-%d', hrtime(true));
            $effects[] = new AdvanceRun(
                runId: $runId,
                turnNo: $state->turnNo,
                stepId: $continueStepId,
                attempt: 1,
                idempotencyKey: hash('sha256', \sprintf('%s|advance|%d|%s', $runId, $state->turnNo, $continueStepId)),
            );
        }

        return new HandlerResult(
            nextState: $nextState,
            events: $events,
            effects: $effects,
        );
    }

    /**
     * @param list<\Ineersa\AgentCore\Domain\Event\RunEvent> $events
     * @param bool                                           $clearActiveStepId when true, set activeStepId to null (terminal outcome)
     * @param RunStatus|null                                 $status            null = preserve current; non-null = override
     *
     * NOTE: this uses a boolean clear flag (false=preserve, true=clear),
     * which inverts CompactRunHandler::incrementState()'s nullable-string
     * semantics (null=preserve, non-null=override). The flag form is used
     * because model_error/empty_summary/success paths genuinely clear the
     * step while stale_result paths genuinely preserve it.
     */
    private function incrementState(RunState $state, array $events, bool $clearActiveStepId = false, ?RunStatus $status = null): RunState
    {
        $count = \count($events);

        return new RunState(
            runId: $state->runId,
            status: $status ?? $state->status,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + $count,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $state->messages,
            activeStepId: $clearActiveStepId ? null : $state->activeStepId,
            retryableFailure: $state->retryableFailure,
        );
    }
}
