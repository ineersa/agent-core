<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;

/**
 * Defines the contract for executing tool calls within the agent core system. It abstracts the execution logic to allow for interchangeable implementations of tool handlers. This interface ensures a consistent return type for tool execution results.
 */
interface ToolExecutorInterface
{
    /**
     * Executes the provided tool call and returns the result.
     */
    public function execute(ToolCall $toolCall): ToolResult;
}
