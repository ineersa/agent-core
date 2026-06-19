<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;

/**
 * Post-execution tool-result processor applied after tool execution and
 * before the canonical ToolCallResult is built.
 *
 * Processors receive the raw domain ToolResult together with the originating
 * ToolCall so they can inspect arguments (e.g. file path for cap selection)
 * and attach structured metadata (e.g. ModelNotificationDTO) to the result
 * before it enters the model-history and TUI projection pipelines.
 *
 * Implementations are auto-discovered via the `agent_core.tool_result_processor`
 * tag on the Symfony service container.
 *
 * Processors MUST be idempotent: the same ToolResult / ToolCall pair
 * produced by idempotency replay must yield the same processed result.
 */
interface ToolResultProcessorInterface
{
    /**
     * Process a tool result after execution.
     *
     * May return the same ToolResult unchanged (no-op) or a new ToolResult
     * with modified content / details.
     *
     * @param ToolResult $result   domain result produced by {@see ToolExecutor}
     * @param ToolCall   $toolCall originating tool call with arguments and context
     */
    public function process(ToolResult $result, ToolCall $toolCall): ToolResult;
}
