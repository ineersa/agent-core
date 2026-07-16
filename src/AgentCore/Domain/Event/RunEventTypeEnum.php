<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

/**
 * Backed enum for all AgentCore RunEvent type strings.
 *
 * Every event type emitted by pipeline handlers MUST use one of these cases
 * instead of raw string literals or constants.
 */
enum RunEventTypeEnum: string
{
    // ── Lifecycle events (ordered stream) ────────────────────────────────
    case AgentStart = 'agent_start';
    case TurnStart = 'turn_start';
    case MessageStart = 'message_start';
    case MessageUpdate = 'message_update';
    case MessageEnd = 'message_end';
    case ToolExecutionStart = 'tool_execution_start';
    case ToolExecutionUpdate = 'tool_execution_update';
    case ToolExecutionEnd = 'tool_execution_end';
    case TurnEnd = 'turn_end';
    case AgentEnd = 'agent_end';

    // ── Pipeline events ──────────────────────────────────────────────────
    case RunStarted = 'run_started';
    case TurnAdvanced = 'turn_advanced';
    case LlmStepCompleted = 'llm_step_completed';
    case LlmStepFailed = 'llm_step_failed';
    case LlmStepAborted = 'llm_step_aborted';
    case WaitingHuman = 'waiting_human';
    case AgentCommandApplied = 'agent_command_applied';
    case AgentCommandRejected = 'agent_command_rejected';
    case AgentCommandQueued = 'agent_command_queued';
    case AgentCommandSuperseded = 'agent_command_superseded';
    case StaleResultIgnored = 'stale_result_ignored';
    case ToolCallResultReceived = 'tool_call_result_received';
    case ToolBatchCommitted = 'tool_batch_committed';
    case ModelNotification = 'model_notification';
    // ── Compaction events ──────────────────────────────────────────────
    case ContextCompactionStarted = 'context_compaction_started';
    case ContextCompacted = 'context_compacted';
    case ContextCompactionFailed = 'context_compaction_failed';
    case RunMessagesReplaced = 'run_messages_replaced';
    // ── Turn tree metadata (append-only canonical) ───────────────────────
    case TurnBranched = 'turn_branched';
    case LeafSet = 'leaf_set';

    /**
     * Whether the given event type string belongs to the ordered lifecycle stream
     * (the 10 core cases: AgentStart through AgentEnd).
     */
    public static function isLifecycleType(string $type): bool
    {
        return match ($type) {
            self::AgentStart->value,
            self::TurnStart->value,
            self::MessageStart->value,
            self::MessageUpdate->value,
            self::MessageEnd->value,
            self::ToolExecutionStart->value,
            self::ToolExecutionUpdate->value,
            self::ToolExecutionEnd->value,
            self::TurnEnd->value,
            self::AgentEnd->value => true,
            default => false,
        };
    }
}
