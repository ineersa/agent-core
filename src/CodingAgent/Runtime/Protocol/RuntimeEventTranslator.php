<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Translates AgentCore RunEvent domain events into stable RuntimeEvent DTOs.
 *
 * Replaces the previous Symfony EventDispatcher subscriber chain with an
 * explicit dispatch table. Each AgentCore event type maps to a closure that
 * produces the corresponding RuntimeEvent (or null to drop it).
 *
 * The HITL-vs-cancel priority for 'agent_command_applied' events is now an
 * explicit if/else in onAgentCommandApplied() rather than Symfony subscriber
 * priority ordering.
 *
 * Approval answer routing was previously handled by an
 * ExtensionApprovalAnswerSubscriber that observed the mapping flow through
 * EventDispatcher. That approach was removed in favor of commit-time
 * routing via ExtensionToolHookEventSubscriber (blocking-poll mechanism),
 * which fires IN the worker process where pending approvals live.
 * The event_dispatcher is still passed for potential extension subscribers.
 */
final class RuntimeEventTranslator
{
    private const string DEBUG_RAW_TYPE = 'debug.raw_type';
    private const string DEBUG_RAW_PAYLOAD = 'debug.raw_payload';

    /** @var array<string, \Closure(RunEvent): ?RuntimeEvent> */
    private readonly array $dispatchTable;

    /**
     * @param EventDispatcherInterface $eventDispatcher Dispatcher for extension subscribers
     *                                                  that observe the mapping flow.
     *                                                  Each translated RunEvent is dispatched by its type string so
     *                                                  observers continue to receive events.
     */
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        $this->dispatchTable = [
            // Lifecycle
            RunEventTypeEnum::RunStarted->value => $this->onRunStarted(...),
            RunEventTypeEnum::TurnAdvanced->value => $this->onTurnStarted(...),
            RunEventTypeEnum::AgentEnd->value => $this->onAgentEnd(...),
            // Assistant stream
            RunEventTypeEnum::LlmStepCompleted->value => $this->onLlmStepCompleted(...),
            RunEventTypeEnum::LlmStepFailed->value => $this->onLlmStepFailed(...),
            RunEventTypeEnum::LlmStepAborted->value => $this->onLlmStepAborted(...),
            // Tool execution
            RunEventTypeEnum::ToolExecutionStart->value => $this->onToolExecutionStarted(...),
            RunEventTypeEnum::ToolExecutionUpdate->value => $this->onToolExecutionUpdate(...),
            RunEventTypeEnum::ToolExecutionEnd->value => $this->onToolExecutionEnded(...),
            // Model notification (generic, tool-scoped for now)
            RunEventTypeEnum::ModelNotification->value => $this->onModelNotification(...),
            // HITL
            RunEventTypeEnum::WaitingHuman->value => $this->onWaitingHuman(...),
            // Shared: agent_command_applied — explicit priority resolution
            RunEventTypeEnum::AgentCommandApplied->value => $this->onAgentCommandApplied(...),
            // Cancel / fallback
            RunEventTypeEnum::AgentCommandRejected->value => $this->onStatusUpdated(...),
            RunEventTypeEnum::StaleResultIgnored->value => $this->onStatusUpdated(...),
            // Compaction
            RunEventTypeEnum::ContextCompactionStarted->value => $this->onCompactionStarted(...),
            RunEventTypeEnum::ContextCompacted->value => $this->onCompactionCompleted(...),
            RunEventTypeEnum::ContextCompactionFailed->value => $this->onCompactionFailed(...),
            // Drop (internal bookkeeping)
            RunEventTypeEnum::ToolCallResultReceived->value => $this->drop(...),
            RunEventTypeEnum::ToolBatchCommitted->value => $this->drop(...),
            RunEventTypeEnum::AgentCommandQueued->value => $this->onAgentCommandQueued(...),
            RunEventTypeEnum::AgentCommandSuperseded->value => $this->drop(...),
            // Drop (turn tree metadata — not user-visible)
            RunEventTypeEnum::TurnBranched->value => $this->drop(...),
            RunEventTypeEnum::LeafSet->value => $this->drop(...),
            // File rewind metadata (not user-visible status flashes)
            RunEventTypeEnum::FileRewindCheckpointRecorded->value => $this->drop(...),
            RunEventTypeEnum::FileRewindRestored->value => $this->drop(...),
        ];
    }

    /**
     * Translate a single AgentCore RunEvent into a RuntimeEvent DTO.
     *
     * Returns null when the event should be dropped from the runtime stream
     * (e.g. internal bookkeeping events like tool_batch_committed).
     * Unknown event types fall through to status.updated with debug metadata.
     */
    public function translate(RunEvent $runEvent): ?RuntimeEvent
    {
        $type = $runEvent->type;

        // Dispatch to extension subscribers that observe the mapping flow.
        // Approval answer routing is now handled at commit time by
        // ExtensionToolHookEventSubscriber (blocking-poll), not through this dispatcher.
        $this->eventDispatcher->dispatch($runEvent, $type);

        if (isset($this->dispatchTable[$type])) {
            return $this->dispatchTable[$type]($runEvent);
        }

        // Unknown event type → status.updated with debug metadata.
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::StatusUpdated->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: [
                self::DEBUG_RAW_TYPE => $runEvent->type,
                self::DEBUG_RAW_PAYLOAD => $runEvent->payload,
            ],
        );
    }

    // ── Lifecycle ──────────────────────────────────────────────────────────

    private function onRunStarted(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;
        $userMessages = $this->extractUserMessages($runEvent);

        $payload = ['step_id' => (string) ($p['step_id'] ?? '')];
        if ([] !== $userMessages) {
            $payload['user_messages'] = $userMessages;
        }

        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: $payload,
        );
    }

    private function onTurnStarted(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;

        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnStarted->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: ['turn_no' => (int) ($p['turn_no'] ?? 0)],
        );
    }

    private function onAgentEnd(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;
        $reason = (string) ($p['reason'] ?? '');

        $type = match ($reason) {
            'cancelled' => RuntimeEventTypeEnum::RunCancelled->value,
            'failed' => RuntimeEventTypeEnum::RunFailed->value,
            default => RuntimeEventTypeEnum::RunCompleted->value,
        };

        return new RuntimeEvent(
            type: $type,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: [
                'reason' => '' !== $reason ? $reason : 'completed',
                'error' => (string) ($p['error'] ?? ''),
                'message_type' => (string) ($p['message_type'] ?? ''),
            ],
        );
    }

    // ── Assistant stream ───────────────────────────────────────────────────

    private function onLlmStepCompleted(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;

        // Prefer the explicit text key when available (source-side extraction
        // via AssistantMessage::asText()).  Fall back to walking the
        // normalized assistant_message content array for backward compat.
        $text = \is_string($p['text'] ?? null) && '' !== $p['text']
            ? $p['text']
            : $this->extractAssistantText($p['assistant_message'] ?? null);

        $payload = [
            'message_id' => (string) ($p['step_id'] ?? ''),
            'text' => $text,
            'stop_reason' => (string) ($p['stop_reason'] ?? ''),
        ];

        if (isset($p['usage'])) {
            $payload['usage'] = $p['usage'];
        }

        // Include canonical thinking details for replay/reconstruction of
        // non-streaming AssistantThinking blocks on resume.
        $assistantMessage = $p['assistant_message'] ?? null;
        if (\is_array($assistantMessage)) {
            $thinking = (string) ($assistantMessage['details']['thinking'] ?? '');
            if ('' !== $thinking) {
                $payload['details'] = ['thinking' => $thinking];
            }

            // Include canonical tool-call data for reconstruction of
            // non-streaming ToolCall blocks on resume.
            $toolCalls = $assistantMessage['tool_calls'] ?? null;
            if (\is_array($toolCalls) && [] !== $toolCalls) {
                $payload['tool_calls'] = $toolCalls;
            }
        }

        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::AssistantMessageCompleted->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: $payload,
        );
    }

    private function onLlmStepFailed(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;
        $error = $p['error'] ?? [];

        // Prefer sanitized user_message from the error classifier when available.
        $errorText = \is_array($error) && isset($error['user_message'])
            ? (string) $error['user_message']
            : (\is_array($error) && isset($error['message'])
                ? (string) $error['message']
                : 'LLM step failed');

        $payload = [
            'message_id' => (string) ($p['step_id'] ?? ''),
            'text' => $errorText,
            'stop_reason' => 'error',
        ];

        // Pass through safe structured diagnostics for projection/TUI context.
        if (\is_array($error)) {
            foreach (['retryable', 'error_category', 'http_status_code', 'retry_after_ms', 'response_error_code', 'response_error_type'] as $key) {
                if (\array_key_exists($key, $error)) {
                    $payload[$key] = $error[$key];
                }
            }
        }

        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::AssistantMessageFailed->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: $payload,
        );
    }

    private function onLlmStepAborted(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;

        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnCancelled->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: ['reason' => (string) ($p['stop_reason'] ?? 'aborted')],
        );
    }

    // ── Tool execution ─────────────────────────────────────────────────────

    private function onToolExecutionStarted(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;

        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionStarted->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: [
                'tool_call_id' => (string) ($p['tool_call_id'] ?? ''),
                'tool_name' => (string) ($p['tool_name'] ?? ''),
                'order_index' => (int) ($p['order_index'] ?? 0),
            ],
        );
    }

    /**
     * Map domain ToolExecutionUpdate → runtime tool_execution.output_delta.
     *
     * Used by SubagentExecutionService to push inline progress updates
     * from child agent runs into the parent's transcript tool result block.
     */
    private function onToolExecutionUpdate(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;

        $payload = [
            'tool_call_id' => (string) ($p['tool_call_id'] ?? ''),
            'delta' => (string) ($p['delta'] ?? ''),
            'order_index' => (int) ($p['order_index'] ?? 0),
        ];
        if (isset($p['tool_name']) && \is_string($p['tool_name']) && '' !== $p['tool_name']) {
            $payload['tool_name'] = $p['tool_name'];
        }
        if (isset($p['subagent_progress']) && \is_array($p['subagent_progress'])) {
            $payload['subagent_progress'] = $p['subagent_progress'];
        }

        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: $payload,
        );
    }

    private function onToolExecutionEnded(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;
        $isError = (bool) ($p['is_error'] ?? false);
        $resultText = isset($p['result']) && \is_string($p['result']) ? $p['result'] : '';

        $payload = [
            'tool_call_id' => (string) ($p['tool_call_id'] ?? ''),
            'is_error' => $isError,
            'order_index' => (int) ($p['order_index'] ?? 0),
        ];

        // Pass through result text when present (e.g. shell command output
        // injected directly via EventStore, bypassing the normal pipeline).
        if ('' !== $resultText) {
            $payload['result'] = $resultText;
        }

        if (isset($p['duration_ms']) && \is_int($p['duration_ms'])) {
            $payload['duration_ms'] = $p['duration_ms'];
        }

        $isStructuredCancel = (bool) ($p['cancelled'] ?? false);
        // Structured metadata is preferred; text heuristic remains for legacy events.
        $isUserCancelled = $isStructuredCancel
            || ($isError && str_contains(strtolower($resultText), 'cancelled by user'));

        $type = match (true) {
            $isUserCancelled => RuntimeEventTypeEnum::ToolExecutionCancelled->value,
            $isError => RuntimeEventTypeEnum::ToolExecutionFailed->value,
            default => RuntimeEventTypeEnum::ToolExecutionCompleted->value,
        };

        return new RuntimeEvent(
            type: $type,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: $payload,
        );
    }

    // ── Model notification ────────────────────────────────────────────────

    private function onModelNotification(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;

        // Pass through the entire notification payload as-is so the
        // projection subscriber receives the exact structured data.
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::ModelNotification->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: $p,
        );
    }

    // ── HITL ───────────────────────────────────────────────────────────────

    /**
     * Generic passthrough from waiting_human to human_input.requested.
     *
     * Starts from the full upstream payload, preserving every key generically.
     * Only the three core fields (question_id, prompt, schema) receive typed
     * fallbacks; all other interrupt fields pass through unchanged. This mirrors
     * the generic-passthrough pattern established in ToolCallExtractor (QH-05).
     */
    private function onWaitingHuman(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;

        // Generic passthrough: carry whatever the upstream interrupt produced.
        $payload = $p;

        $payload['question_id'] = \is_string($payload['question_id'] ?? null)
            ? $payload['question_id']
            : '';
        $payload['prompt'] = \is_string($payload['prompt'] ?? null)
            ? $payload['prompt']
            : 'Human input required.';
        $payload['schema'] = \is_array($payload['schema'] ?? null)
            ? $payload['schema']
            : ['type' => 'string'];

        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputRequested->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: $payload,
        );
    }

    // ── Shared: agent_command_applied ──────────────────────────────────────

    /**
     * Resolve agent_command_applied with explicit priority:
     * steer / follow_up / append_message → user.message_submitted,
     * human_response → human_input.answered,
     * cancel → cancellation.requested,
     * everything else → status.updated.
     */
    private function onAgentCommandApplied(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;
        $kind = (string) ($p['kind'] ?? '');

        if ('human_response' === $kind) {
            return new RuntimeEvent(
                type: RuntimeEventTypeEnum::HumanInputAnswered->value,
                runId: $runEvent->runId,
                seq: $runEvent->seq,
                payload: [
                    'question_id' => (string) ($p['question_id'] ?? ''),
                    'answer' => $p['answer'] ?? null,
                ],
            );
        }

        if ('cancel' === $kind) {
            return new RuntimeEvent(
                type: RuntimeEventTypeEnum::CancellationRequested->value,
                runId: $runEvent->runId,
                seq: $runEvent->seq,
                payload: ['kind' => $kind, 'reason' => 'user_cancelled'],
            );
        }

        if (\in_array($kind, ['steer', 'follow_up', 'append_message'], true)) {
            // Extract message text from the serialized message payload
            // included by CommandMailboxPolicy.
            $messagePayload = $p['message'] ?? [];
            $text = \is_string($p['text'] ?? null) ? $p['text'] : '';
            if ('' === $text && \is_array($messagePayload)) {
                $text = $this->extractTextFromContent($messagePayload['content'] ?? []);
            }
            $idempotencyKey = (string) ($p['idempotency_key'] ?? '');

            return new RuntimeEvent(
                type: RuntimeEventTypeEnum::UserMessageSubmitted->value,
                runId: $runEvent->runId,
                seq: $runEvent->seq,
                payload: [
                    'message_id' => \sprintf('user_%s_%d_%s', $runEvent->runId, $runEvent->seq, $idempotencyKey),
                    'text' => $text,
                    'idempotency_key' => $idempotencyKey,
                ],
            );
        }

        return $this->statusUpdatedEvent($runEvent);
    }

    private function onAgentCommandQueued(RunEvent $runEvent): ?RuntimeEvent
    {
        $p = $runEvent->payload;
        $kind = (string) ($p['kind'] ?? '');

        if (!\in_array($kind, ['steer', 'follow_up', 'append_message'], true)) {
            return null;
        }

        // Idle follow_up commands apply immediately (AdvanceRun on queue drain) and
        // project as a canonical ❯ user message moments later. Emitting
        // user.message_queued for follow_up only causes a brief ⏳ flicker with no
        // user value. Steer/append_message while the run is active still need the
        // pending-queue affordance until agent_command_applied arrives.
        if ('follow_up' === $kind) {
            return null;
        }

        $text = \is_string($p['text'] ?? null) ? $p['text'] : '';
        if ('' === $text) {
            $text = $this->extractTextFromContent($p['message']['content'] ?? []);
        }

        $idempotencyKey = (string) ($p['idempotency_key'] ?? '');

        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::UserMessageQueued->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: [
                'text' => $text,
                'idempotency_key' => $idempotencyKey,
            ],
        );
    }

    // ── Cancel / fallback ──────────────────────────────────────────────────

    private function onStatusUpdated(RunEvent $runEvent): RuntimeEvent
    {
        return $this->statusUpdatedEvent($runEvent);
    }

    private function statusUpdatedEvent(RunEvent $runEvent): RuntimeEvent
    {
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::StatusUpdated->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: [
                self::DEBUG_RAW_TYPE => $runEvent->type,
                self::DEBUG_RAW_PAYLOAD => $runEvent->payload,
            ],
        );
    }

    // ── Compaction ────────────────────────────────────────────────────────

    private function onCompactionStarted(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;

        // CompactRunHandler emits 'estimated_tokens' (singular).
        // Normalise to 'estimated_tokens_before' for downstream consumers.
        $estimatedTokens = $p['estimated_tokens'] ?? $p['estimated_tokens_before'] ?? null;

        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionStarted->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: [
                'estimated_tokens_before' => $estimatedTokens,
            ],
        );
    }

    private function onCompactionCompleted(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;

        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionCompleted->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: [
                'estimated_tokens_before' => $p['estimated_tokens_before'] ?? null,
                'estimated_tokens_after' => $p['estimated_tokens_after'] ?? null,
                'messages_before' => $p['messages_before'] ?? null,
                'messages_after' => $p['messages_after'] ?? null,
            ],
        );
    }

    private function onCompactionFailed(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;
        $reason = (string) ($p['reason'] ?? 'Compaction failed.');

        // Map internal reason strings to user-friendly messages.
        // Wording mirrors CompactRunHandler::failureReasonToMessage()
        // for prep-not-ready structural failures.
        //
        // model_error prefers the sanitised user_message from the error
        // classifier (produced by CompactionStepResultHandler).
        $userMessage = match ($reason) {
            'too_few_messages' => 'Compaction failed: there are not enough messages to compact.',
            'below_keep_recent_tokens' => 'Compaction failed: there is no older context outside the retained tail to summarize.',
            'no_boundary' => 'Compaction failed: could not determine a boundary for the retained tail.',
            'no_safe_boundary' => 'Compaction failed: no safe boundary found without splitting tool-call results.',
            'empty_summary' => 'Compaction failed: The model returned an empty summary.',
            'ineffective_compaction' => 'Compaction failed: the compacted context was not smaller than the original.',
            'model_error' => $this->compactionModelErrorMessage($p),
            'stale_result' => 'Compaction result is no longer relevant — the conversation has moved on.',
            default => \sprintf('Compaction failed: %s', $reason),
        };

        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionFailed->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: [
                'reason' => $reason,
                'error' => $userMessage,
            ],
        );
    }

    // ── Drop ───────────────────────────────────────────────────────────────

    private function drop(RunEvent $runEvent): null
    {
        return null;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Extract initial user messages from the normalized StartRunPayload so
     * events.jsonl replay can project user message transcript blocks.
     *
     * Only role===user messages with non-empty text are included; system and
     * context messages are intentionally skipped to avoid leaking full prompts.
     *
     * @return list<array{message_id: string, text: string}>
     */
    private function extractUserMessages(RunEvent $runEvent): array
    {
        $normalizedPayload = $runEvent->payload['payload'] ?? [];
        $messages = $normalizedPayload['messages'] ?? [];
        if (!\is_array($messages)) {
            return [];
        }

        $userMessages = [];
        foreach ($messages as $msg) {
            $role = (string) ($msg['role'] ?? '');
            if ('user' !== $role) {
                continue;
            }
            $text = $this->extractTextFromContent($msg['content'] ?? []);
            if ('' !== $text) {
                $userMessages[] = [
                    'message_id' => \sprintf('initial_%s_%d', $runEvent->runId, \count($userMessages)),
                    'text' => $text,
                ];
            }
        }

        return $userMessages;
    }

    /**
     * Legacy fallback: extract text from the normalized assistant_message
     * payload array produced by AgentMessageNormalizer::assistantMessagePayload().
     *
     * @param mixed $assistantMessage Typically array{content: list<array{type: string, text: string}>}
     */
    private function extractAssistantText(mixed $assistantMessage): string
    {
        return \is_array($assistantMessage)
            ? $this->extractTextFromContent($assistantMessage['content'] ?? null)
            : '';
    }

    /**
     * Extract text content from a content array (list of typed content blocks).
     *
     * @param array<int, array<string, mixed>>|mixed $content
     */
    private function extractTextFromContent(mixed $content): string
    {
        if (!\is_array($content) || [] === $content) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (\is_array($block) && isset($block['text']) && ('text' === ($block['type'] ?? null))) {
                $parts[] = (string) $block['text'];
            }
        }

        return implode('', $parts);
    }

    /**
     * Build a user-visible error message for compaction model_error results.
     *
     * Prefers the sanitised user_message from the error classifier (stored
     * by CompactionStepResultHandler) when present and non-empty.  Falls
     * back to the raw producer message (capped by the worker), then to a
     * generic fallback.
     *
     * @param array<string, mixed> $p The raw context_compaction_failed payload
     */
    private function compactionModelErrorMessage(array $p): string
    {
        // Prefer sanitised user_message from the error classifier.
        // CompactionStepResultHandler stores this in the 'message' key
        // (not in a separate 'user_message' key — the compaction payload
        // is flat, unlike the LlmStepFailed->error array shape).
        // The value in 'message' is already the classifier's user_message
        // when available, or a capped raw message with generic wrapper when
        // the classifier was not run (worker catch path).
        $detail = \is_string($p['message'] ?? null) ? trim($p['message']) : '';

        if ('' !== $detail) {
            // The message is already a full display string from
            // CompactionStepResultHandler (classifier user_message or
            // worker fallback).  Use it verbatim.
            if (str_starts_with($detail, 'Compaction failed')) {
                return $detail;
            }

            // Bare diagnostic — prefix for display consistency.
            return \sprintf('Compaction failed: %s', $detail);
        }

        return 'Compaction failed: The summarization model returned an unexpected error.';
    }
}
