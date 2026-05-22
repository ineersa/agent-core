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

    case ProgressUpdated = 'progress.updated';
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

    // ── Model / usage / cost metadata ───────────────────────────────────

    case ModelChanged = 'model.changed';
    case ReasoningChanged = 'reasoning.changed';
    case UsageUpdated = 'usage.updated';
    case ContextUpdated = 'context.updated';
    case CostUpdated = 'cost.updated';

    // ── Command protocol (controller <-> TUI) ───────────────────────────────

    case CommandAck = 'command.ack';
    case CommandRejected = 'command.rejected';

    // ── Runtime lifecycle (controller process) ─────────────────────────────

    case RuntimeReady = 'runtime.ready';
    case ProtocolError = 'protocol.error';
    case RunResumed = 'run.resumed';

    /**
     * Return the event family name for grouping and documentation.
     *
     * @return string One of: lifecycle, user_input, assistant_stream, tool,
     *                progress, hitl, cancellation, metadata, runtime, protocol
     */
    public function family(): string
    {
        return match ($this) {
            self::RunStarted, self::TurnStarted, self::TurnCompleted,
            self::TurnFailed, self::TurnCancelled, self::RunCompleted,
            self::RunFailed, self::RunCancelled, self::RunResumed => 'lifecycle',

            self::UserMessageSubmitted => 'user_input',

            self::AssistantMessageStarted, self::AssistantTextStarted,
            self::AssistantTextDelta, self::AssistantTextCompleted,
            self::AssistantThinkingStarted, self::AssistantThinkingDelta,
            self::AssistantThinkingCompleted, self::AssistantMessageCompleted,
            self::AssistantMessageFailed => 'assistant_stream',

            self::ToolCallStarted, self::ToolCallArgumentsDelta,
            self::ToolCallArgumentsCompleted, self::ToolExecutionStarted,
            self::ToolExecutionOutputDelta, self::ToolExecutionCompleted,
            self::ToolExecutionFailed, self::ToolExecutionCancelled => 'tool',

            self::ProgressUpdated, self::StatusUpdated => 'progress',

            self::HumanInputRequested, self::HumanInputAnswered,
            self::HumanInputRejected, self::ApprovalRequested,
            self::ApprovalApproved, self::ApprovalRejected => 'hitl',

            self::CancellationRequested, self::OperationCancelled => 'cancellation',

            self::CommandAck, self::CommandRejected => 'command',

            self::RuntimeReady => 'runtime',
            self::ProtocolError => 'protocol',

            self::ModelChanged, self::ReasoningChanged, self::UsageUpdated,
            self::ContextUpdated, self::CostUpdated => 'metadata',
        };
    }

    /**
     * Return true when the event type belongs to the assistant stream family.
     */
    public function isAssistantStream(): bool
    {
        return 'assistant_stream' === $this->family();
    }

    /**
     * Return true when the event type belongs to the tool call/execution family.
     */
    public function isTool(): bool
    {
        return 'tool' === $this->family();
    }

    /**
     * Return true when the event type belongs to the run/turn lifecycle family.
     */
    public function isLifecycle(): bool
    {
        return 'lifecycle' === $this->family();
    }

    /**
     * Return true when the event type belongs to the HITL family.
     */
    public function isHitl(): bool
    {
        return 'hitl' === $this->family();
    }

    /**
     * Return true when the event type belongs to the cancellation family.
     */
    public function isCancellation(): bool
    {
        return 'cancellation' === $this->family();
    }

    /**
     * Return true when the event type belongs to the runtime family.
     */
    public function isRuntime(): bool
    {
        return 'runtime' === $this->family();
    }

    /**
     * Return true when the event type belongs to the protocol family.
     */
    public function isProtocol(): bool
    {
        return 'protocol' === $this->family();
    }
}
