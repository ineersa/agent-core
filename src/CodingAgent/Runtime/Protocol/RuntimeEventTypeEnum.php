<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * Stable runtime event name constants.
 *
 * Every RuntimeEvent emitted by the runtime MUST use one of these type strings
 * to ensure stable tooling, projections, and replay without coupling to raw
 * AgentCore RunEvent type names.
 *
 * Refer to AGENTS.md in this directory for payload shape documentation.
 */
enum RuntimeEventTypeEnum: string
{
    // ── Run/turn lifecycle ──────────────────────────────────────────────

    case RunStarted = 'run.started';
    case TurnStarted = 'turn.started';
    case TurnCompleted = 'turn.completed';
    case TurnFailed = 'turn.failed';
    case TurnCancelled = 'turn.cancelled';
    case RunCompleted = 'run.completed';
    case RunFailed = 'run.failed';
    case RunCancelled = 'run.cancelled';

    // ── User input ──────────────────────────────────────────────────────

    case UserMessageSubmitted = 'user.message_submitted';
    case UserMessageQueued = 'user.message_queued';

    // ── Assistant message stream ────────────────────────────────────────

    case AssistantMessageStarted = 'assistant.message_started';
    case AssistantTextStarted = 'assistant.text_started';
    case AssistantTextDelta = 'assistant.text_delta';
    case AssistantTextCompleted = 'assistant.text_completed';
    case AssistantThinkingStarted = 'assistant.thinking_started';
    case AssistantThinkingDelta = 'assistant.thinking_delta';
    case AssistantThinkingCompleted = 'assistant.thinking_completed';
    case AssistantMessageCompleted = 'assistant.message_completed';
    case AssistantMessageFailed = 'assistant.message_failed';

    // ── Tool call lifecycle ─────────────────────────────────────────────

    case ToolCallStarted = 'tool_call.started';
    case ToolCallArgumentsDelta = 'tool_call.arguments_delta';
    case ToolCallArgumentsCompleted = 'tool_call.arguments_completed';
    case ToolExecutionStarted = 'tool_execution.started';
    case ToolExecutionOutputDelta = 'tool_execution.output_delta';
    case ToolExecutionCompleted = 'tool_execution.completed';
    case ToolExecutionFailed = 'tool_execution.failed';
    case ToolExecutionCancelled = 'tool_execution.cancelled';

    // ── Progress / status ────────────────────────────────────────────────

    case StatusUpdated = 'status.updated';

    // ── Human-in-the-loop (AgentCore HITL only) ─────────────────────────

    case HumanInputRequested = 'human_input.requested';
    case HumanInputAnswered = 'human_input.answered';
    case HumanInputRejected = 'human_input.rejected';
    case ApprovalRequested = 'approval.requested';
    case ApprovalApproved = 'approval.approved';
    case ApprovalRejected = 'approval.rejected';

    // ── Cancellation / interruption ─────────────────────────────────────
    //
    // Note: turn.cancelled and run.cancelled are also listed under
    // lifecycle above; the same string values serve both families.

    case CancellationRequested = 'cancellation.requested';
    case OperationCancelled = 'operation.cancelled';

    // ── Command protocol (controller <-> TUI) ───────────────────────────────

    case CommandAck = 'command.ack';
    case CommandRejected = 'command.rejected';

    // ── Tool-local questions ─────────────────────────────────────────────────────
    // Used by tool workers (e.g. BashTool) to prompt the user via the TUI
    // question overlay without entering AgentCore WaitingHuman. These events
    // carry transcript=false and do not create transcript blocks.

    case ToolQuestionRequested = 'tool_question.requested';

    // ── Background process completion ───────────────────────────────────────────────────
    // Emitted by BackgroundProcessCompletionPoller when a process that was
    // explicitly moved to background finishes. Carries pid, exit_code, status,
    // command_preview, output_tail in payload.

    case BackgroundProcessCompleted = 'bg_process.completed';

    // ── Extension agent jobs ────────────────────────────────────────────────────────────
    // Emitted by Extension\Agent\ExtensionAgentJobFailedEventSubscriber after the extension_agent
    // transport exhausts retries (max_retries: 1). Transient JSONL/TUI only;
    // does not mark the main agent run failed.

    case ExtensionAgentJobFailed = 'extension_agent.job_failed';

    // ── Runtime lifecycle (controller process) ─────────────────────────────

    case RuntimeReady = 'runtime.ready';
    case ProtocolError = 'protocol.error';
    case RunResumed = 'run.resumed';
    case RunHistoryPositionChanged = 'run.history_position_changed';

    // ── Compaction ────────────────────────────────────────────────────

    case CompactionStarted = 'compaction.started';
    case CompactionCompleted = 'compaction.completed';
    case CompactionFailed = 'compaction.failed';

    // ── Model notification ────────────────────────────────────────────

    case ModelNotification = 'model.notification';
}
