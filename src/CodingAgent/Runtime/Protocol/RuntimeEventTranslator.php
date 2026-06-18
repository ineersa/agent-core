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
 * Extension subscribers (e.g. ExtensionApprovalAnswerSubscriber) that
 * previously observed the mapping flow through EventDispatcher now receive
 * the raw RunEvent via an optional dispatcher passed here.
 */
final class RuntimeEventTranslator
{
    private const string DEBUG_RAW_TYPE = 'debug.raw_type';
    private const string DEBUG_RAW_PAYLOAD = 'debug.raw_payload';

    /**
     * Prefix used by RunEvent::extension() factory for extension-originated events.
     */
    private const string EXT_PREFIX = 'ext_';

    /** @var array<string, \Closure(RunEvent): ?RuntimeEvent> */
    private readonly array $dispatchTable;

    /**
     * @param EventDispatcherInterface $eventDispatcher Dispatcher for extension subscribers
     *                                                  that observe the mapping flow (e.g. ExtensionApprovalAnswerSubscriber).
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
            RunEventTypeEnum::ToolExecutionEnd->value => $this->onToolExecutionEnded(...),
            // HITL
            RunEventTypeEnum::WaitingHuman->value => $this->onWaitingHuman(...),
            // Shared: agent_command_applied — explicit priority resolution
            RunEventTypeEnum::AgentCommandApplied->value => $this->onAgentCommandApplied(...),
            // Cancel / fallback
            RunEventTypeEnum::AgentCommandRejected->value => $this->onStatusUpdated(...),
            RunEventTypeEnum::StaleResultIgnored->value => $this->onStatusUpdated(...),
            // Drop (internal bookkeeping)
            RunEventTypeEnum::ToolCallResultReceived->value => $this->drop(...),
            RunEventTypeEnum::ToolBatchCommitted->value => $this->drop(...),
            RunEventTypeEnum::AgentCommandQueued->value => $this->drop(...),
            RunEventTypeEnum::AgentCommandSuperseded->value => $this->drop(...),
            // Drop (turn tree metadata — not user-visible)
            RunEventTypeEnum::TurnBranched->value => $this->drop(...),
            RunEventTypeEnum::LeafSet->value => $this->drop(...),
            // ext_* extension events are resolved at translate() time
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

        // Dispatch to extension subscribers that observe the mapping flow
        // (e.g. ExtensionApprovalAnswerSubscriber).
        $this->eventDispatcher->dispatch($runEvent, $type);

        if (isset($this->dispatchTable[$type])) {
            return $this->dispatchTable[$type]($runEvent);
        }

        // Extension-originated events with ext_* prefix → system.notice.
        // These replace the previous fallthrough to opaque status.updated.
        if (str_starts_with($type, self::EXT_PREFIX)) {
            return $this->onExtensionEvent($runEvent);
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

        // Pass through exact model-facing message content for
        // projection.  The ToolProjectionSubscriber uses this to
        // replace raw tool result text with the exact text the model
        // saw (capped, denied, or otherwise hook-transformed).
        $modelInputMessages = $p['model_input_messages'] ?? null;
        if (\is_array($modelInputMessages) && [] !== $modelInputMessages) {
            $payload['model_input_messages'] = $modelInputMessages;
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

        // Pass through exact model-facing message content for projection.
        $modelInputMessages = $p['model_input_messages'] ?? null;
        if (\is_array($modelInputMessages) && [] !== $modelInputMessages) {
            $payload['model_input_messages'] = $modelInputMessages;
        }

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

        $payload = ['reason' => (string) ($p['stop_reason'] ?? 'aborted')];

        // Pass through exact model-facing message content for projection.
        $modelInputMessages = $p['model_input_messages'] ?? null;
        if (\is_array($modelInputMessages) && [] !== $modelInputMessages) {
            $payload['model_input_messages'] = $modelInputMessages;
        }

        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnCancelled->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: $payload,
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

    private function onToolExecutionEnded(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;
        $isError = (bool) ($p['is_error'] ?? false);

        $payload = [
            'tool_call_id' => (string) ($p['tool_call_id'] ?? ''),
            'is_error' => $isError,
            'order_index' => (int) ($p['order_index'] ?? 0),
        ];

        // Pass through result text when present (e.g. shell command output
        // injected directly via EventStore, bypassing the normal pipeline).
        if (isset($p['result']) && \is_string($p['result'])) {
            $payload['result'] = $p['result'];
        }

        if (isset($p['duration_ms']) && \is_int($p['duration_ms'])) {
            $payload['duration_ms'] = $p['duration_ms'];
        }

        // Detect output cap notice in the result text and add structured
        // metadata so the projector can create a visible System block.
        $result = $payload['result'] ?? '';
        // Strict starts-with check: only detect the canonical cap notice at
        // the beginning of the result text.  File/tool output that merely
        // contains the marker string must not trigger warning styling.
        if ('' !== $result && str_starts_with(ltrim($result), '[Output capped to')) {
            $payload['output_capped'] = true;

            // Parse cap and char_count from the notice format:
            // "[Output capped to %d characters]\n\nFull output: %d characters (~%d tokens).\nSaved for audit at: %s"
            if (preg_match('/Output capped to (\d+) characters/', $result, $m)) {
                $payload['output_cap_limit'] = (int) $m[1];
            }
            if (preg_match('/Full output: (\d+) characters/', $result, $m)) {
                $payload['output_cap_char_count'] = (int) $m[1];
            }
            if (preg_match('/Saved for audit at: (\S+)/', $result, $m)) {
                $payload['output_cap_saved_path'] = $m[1];
            }
        }

        return new RuntimeEvent(
            type: $isError
                ? RuntimeEventTypeEnum::ToolExecutionFailed->value
                : RuntimeEventTypeEnum::ToolExecutionCompleted->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: $payload,
        );
    }

    // ── HITL ───────────────────────────────────────────────────────────────

    private function onWaitingHuman(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;

        $payload = [
            'question_id' => (string) ($p['question_id'] ?? ''),
            'prompt' => (string) ($p['prompt'] ?? 'Human input required.'),
        ];

        if (isset($p['schema'])) {
            $payload['schema'] = $p['schema'];
        }
        if (isset($p['tool_call_id'])) {
            $payload['tool_call_id'] = $p['tool_call_id'];
        }
        if (isset($p['tool_name'])) {
            $payload['tool_name'] = $p['tool_name'];
        }

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
     * steer/follow_up → user.message_submitted,
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
                    'answer' => (string) ($p['answer'] ?? ''),
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

        if (\in_array($kind, ['steer', 'follow_up'], true)) {
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
                ],
            );
        }

        return $this->statusUpdatedEvent($runEvent);
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

    // ── Drop ───────────────────────────────────────────────────────────────

    private function drop(RunEvent $runEvent): null
    {
        return null;
    }

    // ── Extension events ─────────────────────────────────────────────────

    /**
     * Translate extension-originated (ext_*) RunEvents to system.notice.
     *
     * Safely extracts message/title/severity from the payload without dumping
     * raw prompts, tool output, or environment secrets into the notice text.
     */
    private function onExtensionEvent(RunEvent $runEvent): RuntimeEvent
    {
        $p = $runEvent->payload;

        // Extract safe display fields from the extension payload.
        $sourceType = $runEvent->type;
        $message = (string) ($p['message'] ?? $p['title'] ?? '');
        $title = (string) ($p['title'] ?? '');
        $severity = (string) ($p['severity'] ?? 'info');

        // Build a compact text representation.
        $text = '';
        if ('' !== $title) {
            $text = $title;
        }
        if ('' !== $message) {
            $text = '' !== $text ? $text.': '.$message : $message;
        }
        if ('' === $text) {
            $text = \sprintf('Extension event: %s', $sourceType);
        }

        $payload = [
            'source' => $sourceType,
            'severity' => $severity,
            'text' => $text,
        ];

        // Include compact, safe details if present (not full prompts/output).
        if (isset($p['details']) && \is_array($p['details'])) {
            // Only forward safe scalar/array keys; drop anything that looks
            // like prompts, raw output, or environment data.
            $safeDetails = [];
            foreach ($p['details'] as $key => $value) {
                if (\is_scalar($value) || (\is_array($value) && \count($value) <= 5)) {
                    $safeDetails[$key] = $value;
                }
            }
            if ([] !== $safeDetails) {
                $payload['details'] = $safeDetails;
            }
        }

        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::SystemNotice->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: $payload,
        );
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
}
