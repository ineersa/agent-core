<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Normalizes agent-core RunEvent domain events into stable runtime protocol RuntimeEvent DTOs.
 *
 * This is the sole bridge between agent-core event types and the runtime protocol.
 * TUI code must never import RunEvent or AgentCore internals directly.
 *
 * The mapper translates coarse AgentCore event names (agent_end, llm_step_completed,
 * tool_execution_start, waiting_human, etc.) into RuntimeEventTypeEnum values, and
 * reshapes payloads to match the documented runtime event contract in AGENTS.md.
 *
 * Events that have no meaningful transcript projection are dropped (returned as null
 * from the private handler, so the public API skips them).
 */
final class RuntimeEventMapper
{
    private const string DEBUG_RAW_TYPE = 'debug.raw_type';
    private const string DEBUG_RAW_PAYLOAD = 'debug.raw_payload';

    /**
     * Convert a single RunEvent to a RuntimeEvent with normalized type and payload.
     *
     * Returns null when the AgentCore event should not appear in the runtime
     * stream (e.g. internal bookkeeping events like tool_batch_committed).
     */
    public function toRuntimeEvent(RunEvent $runEvent): ?RuntimeEvent
    {
        $rawType = $runEvent->type;
        $rawPayload = $runEvent->payload;

        $result = $this->normalizeEvent($runEvent, $rawType, $rawPayload);

        if (null === $result) {
            return null;
        }

        return new RuntimeEvent(
            type: $result['type'],
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: $result['payload'],
        );
    }

    /**
     * Convert a RuntimeEvent back to a RunEvent-like array.
     *
     * The type field carries the normalized runtime type by default.
     * Raw AgentCore type is preserved in debug metadata when available.
     *
     * @return array{runId: string, seq: int, turnNo: int, type: string, payload: array<string, mixed>}
     */
    public function toRunEventData(RuntimeEvent $event): array
    {
        return [
            'runId' => $event->runId,
            'seq' => $event->seq,
            'turnNo' => 0,
            'type' => $event->type,
            'payload' => $event->payload,
        ];
    }

    // ── Normalization dispatch ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{type: string, payload: array<string, mixed>}|null
     */
    private function normalizeEvent(RunEvent $runEvent, string $rawType, array $payload): ?array
    {
        return match ($rawType) {
            // ── Lifecycle ────────────────────────────────────────────────
            'run_started' => $this->normalizeRunStarted($payload),
            'turn_advanced' => $this->normalizeTurnStarted($payload),
            'agent_end' => $this->normalizeAgentEnd($payload),

            // ── LLM step ─────────────────────────────────────────────────
            'llm_step_completed' => $this->normalizeAssistantMessageCompleted($payload),
            'llm_step_failed' => $this->normalizeAssistantMessageFailed($payload),
            'llm_step_aborted' => $this->normalizeTurnCancelled($payload),

            // ── Tool ─────────────────────────────────────────────────────
            'tool_execution_start' => $this->normalizeToolExecutionStarted($payload),
            'tool_execution_end' => $this->normalizeToolExecutionEnded($payload),

            // ── Internal bookkeeping events (skip) ───────────────────────
            'tool_call_result_received',
            'tool_batch_committed',
            'agent_command_queued',
            'agent_command_superseded' => null,

            // ── HITL ─────────────────────────────────────────────────────
            'waiting_human' => $this->normalizeHumanInputRequested($payload),

            // ── Cancel ───────────────────────────────────────────────────
            'agent_command_applied' => $this->normalizeAgentCommandApplied($payload),

            // ── Fallbacks ────────────────────────────────────────────────
            'agent_command_rejected',
            'stale_result_ignored' => $this->normalizeStatusUpdated($rawType, $payload),

            // ── Unknown → stable fallback with debug metadata ────────────
            default => $this->normalizeUnknownEvent($rawType, $payload),
        };
    }

    // ── Lifecycle handlers ───────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $p
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function normalizeRunStarted(array $p): array
    {
        return [
            'type' => RuntimeEventTypeEnum::RunStarted->value,
            'payload' => [
                'step_id' => (string) ($p['step_id'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function normalizeTurnStarted(array $p): array
    {
        return [
            'type' => RuntimeEventTypeEnum::TurnStarted->value,
            'payload' => [
                'turn_no' => (int) ($p['turn_no'] ?? 0),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function normalizeAgentEnd(array $p): array
    {
        $reason = (string) ($p['reason'] ?? '');

        $type = match ($reason) {
            'cancelled' => RuntimeEventTypeEnum::RunCancelled->value,
            'failed' => RuntimeEventTypeEnum::RunFailed->value,
            default => RuntimeEventTypeEnum::RunCompleted->value,
        };

        return [
            'type' => $type,
            'payload' => ['reason' => '' !== $reason ? $reason : 'completed'],
        ];
    }

    // ── Assistant stream handlers ────────────────────────────────────────────

    /**
     * Normalizes llm_step_completed → assistant.message_completed.
     *
     * Extracts the full assistant message text from the normalized
     * assistant_message payload and emits a single completion event.
     * Individual streaming deltas are NOT available at the event level
     * in the current AgentCore pipeline.
     *
     * @param array<string, mixed> $p
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function normalizeAssistantMessageCompleted(array $p): array
    {
        $assistantMessage = $p['assistant_message'] ?? [];
        $text = $this->extractAssistantText($assistantMessage);

        $payload = [
            'message_id' => (string) ($p['step_id'] ?? ''),
            'text' => $text,
            'stop_reason' => (string) ($p['stop_reason'] ?? ''),
        ];

        if (isset($p['usage'])) {
            $payload['usage'] = $p['usage'];
        }

        return [
            'type' => RuntimeEventTypeEnum::AssistantMessageCompleted->value,
            'payload' => $payload,
        ];
    }

    /**
     * Normalizes llm_step_failed → assistant.message_failed.
     *
     * @param array<string, mixed> $p
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function normalizeAssistantMessageFailed(array $p): array
    {
        $error = $p['error'] ?? [];
        $errorText = \is_array($error) && isset($error['message'])
            ? (string) $error['message']
            : 'LLM step failed';

        return [
            'type' => RuntimeEventTypeEnum::AssistantMessageFailed->value,
            'payload' => [
                'message_id' => (string) ($p['step_id'] ?? ''),
                'text' => $errorText,
                'stop_reason' => 'error',
            ],
        ];
    }

    /**
     * Normalizes llm_step_aborted → turn.cancelled.
     *
     * @param array<string, mixed> $p
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function normalizeTurnCancelled(array $p): array
    {
        return [
            'type' => RuntimeEventTypeEnum::TurnCancelled->value,
            'payload' => [
                'reason' => (string) ($p['stop_reason'] ?? 'aborted'),
            ],
        ];
    }

    // ── Tool handlers ────────────────────────────────────────────────────────

    /**
     * Normalizes tool_execution_start → tool_execution.started.
     *
     * @param array<string, mixed> $p
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function normalizeToolExecutionStarted(array $p): array
    {
        return [
            'type' => RuntimeEventTypeEnum::ToolExecutionStarted->value,
            'payload' => [
                'tool_call_id' => (string) ($p['tool_call_id'] ?? ''),
                'tool_name' => (string) ($p['tool_name'] ?? ''),
                'order_index' => (int) ($p['order_index'] ?? 0),
            ],
        ];
    }

    /**
     * Normalizes tool_execution_end → tool_execution.completed or .failed.
     *
     * @param array<string, mixed> $p
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function normalizeToolExecutionEnded(array $p): array
    {
        $isError = (bool) ($p['is_error'] ?? false);

        return [
            'type' => $isError
                ? RuntimeEventTypeEnum::ToolExecutionFailed->value
                : RuntimeEventTypeEnum::ToolExecutionCompleted->value,
            'payload' => [
                'tool_call_id' => (string) ($p['tool_call_id'] ?? ''),
                'is_error' => $isError,
                'order_index' => (int) ($p['order_index'] ?? 0),
            ],
        ];
    }

    // ── HITL handlers ────────────────────────────────────────────────────────

    /**
     * Normalizes waiting_human → human_input.requested.
     *
     * Extracts question_id, prompt, schema, tool_call_id and tool_name
     * from the interrupt payload produced by ToolCallExtractor.
     *
     * @param array<string, mixed> $p
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function normalizeHumanInputRequested(array $p): array
    {
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

        return [
            'type' => RuntimeEventTypeEnum::HumanInputRequested->value,
            'payload' => $payload,
        ];
    }

    // ── Cancel / command handlers ────────────────────────────────────────────

    /**
     * Normalizes agent_command_applied.
     *
     * Cancel commands produce cancellation.requested; other commands
     * produce status.updated with debug metadata.
     *
     * @param array<string, mixed> $p
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function normalizeAgentCommandApplied(array $p): array
    {
        $kind = (string) ($p['kind'] ?? '');

        if ('cancel' === $kind) {
            return [
                'type' => RuntimeEventTypeEnum::CancellationRequested->value,
                'payload' => [
                    'kind' => $kind,
                    'reason' => 'user_cancelled',
                ],
            ];
        }

        return $this->normalizeStatusUpdated('agent_command_applied', $p);
    }

    // ── Fallback handlers ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $p
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function normalizeStatusUpdated(string $rawType, array $p): array
    {
        return [
            'type' => RuntimeEventTypeEnum::StatusUpdated->value,
            'payload' => [
                self::DEBUG_RAW_TYPE => $rawType,
                self::DEBUG_RAW_PAYLOAD => $p,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function normalizeUnknownEvent(string $rawType, array $p): array
    {
        return [
            'type' => RuntimeEventTypeEnum::StatusUpdated->value,
            'payload' => [
                self::DEBUG_RAW_TYPE => $rawType,
                self::DEBUG_RAW_PAYLOAD => $p,
            ],
        ];
    }

    // ── Payload extraction helpers ───────────────────────────────────────────

    /**
     * Extract the full assistant text from an AssistantMessage normalized payload.
     *
     * The normalized payload structure from AgentMessageNormalizer:
     *   ['content' => [['type' => 'text', 'text' => '...'], ...]]
     *
     * @param array<string, mixed>|mixed $assistantMessage
     */
    private function extractAssistantText(mixed $assistantMessage): string
    {
        if (!\is_array($assistantMessage)) {
            return '';
        }

        $content = $assistantMessage['content'] ?? null;
        if (!\is_array($content) || [] === $content) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (\is_array($block) && isset($block['text']) && ('text' === ($block['type'] ?? null))) {
                $parts[] = (string) $block['text'];
            }
        }

        return [] !== $parts ? implode('', $parts) : '';
    }
}
