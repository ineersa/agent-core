<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Projection;

/**
 * Kinds of transcript blocks produced by the transcript projector.
 *
 * Each block in the transcript has a kind that determines how it is rendered
 * and what metadata it carries. This enum is the stable contract between
 * runtime projection and TUI rendering.
 */
enum TranscriptBlockKindEnum: string
{
    /** A user-submitted message. */
    case UserMessage = 'user_message';

    /** A user steer/follow-up queued during active work; shown as pending until applied. */
    case UserMessageQueued = 'user_message_queued';

    /** A completed assistant message (final text). */
    case AssistantMessage = 'assistant_message';

    /** An assistant thinking/reasoning block. */
    case AssistantThinking = 'assistant_thinking';

    /** A tool call (arguments, in-progress or completed). */
    case ToolCall = 'tool_call';

    /** A tool execution result or error. */
    case ToolResult = 'tool_result';

    /** A progress/status indicator. */
    case Progress = 'progress';

    /** A question requiring human input (AgentCore HITL). */
    case Question = 'question';

    /** An approval request (AgentCore HITL). */
    case Approval = 'approval';

    /** A cancelled operation marker. */
    case Cancelled = 'cancelled';

    /** An error block. */
    case Error = 'error';

    /** A system message or notification. */
    case System = 'system';
}
