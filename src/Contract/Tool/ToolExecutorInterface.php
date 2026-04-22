<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;

/**
 * Executes a tool call and returns the structured result.
 */
interface ToolExecutorInterface
{
    public function execute(ToolCall $toolCall): ToolResult;
}
