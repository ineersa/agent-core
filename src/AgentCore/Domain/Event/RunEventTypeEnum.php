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
    case ToolExecutionStart = 'tool_execution_start';
    case ToolExecutionUpdate = 'tool_execution_update';
    case ToolExecutionEnd = 'tool_execution_end';
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
    case ToolBatchCommitted = 'tool_batch_committed';
    case ModelNotification = 'model_notification';
    // ── Compaction events ──────────────────────────────────────────────
    case ContextCompactionRequested = 'context_compaction_requested';
    case ContextCompactionStarted = 'context_compaction_started';
    case ContextCompacted = 'context_compacted';
    case ContextCompactionFailed = 'context_compaction_failed';
    // ── Linear history metadata (append-only canonical) ───────────────────
    /** Current selected retained position (tip / undo cursor). */
    case HistoryPositionSet = 'history_position_set';
    /** Abandons every active turn after after_turn_no; bytes remain audit-only. */
    case HistoryTailDiscarded = 'history_tail_discarded';
}
