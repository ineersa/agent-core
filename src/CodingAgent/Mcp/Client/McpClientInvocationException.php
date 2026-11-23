<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Client;

/**
 * Client-layer exception for MCP tool invocation failures.
 *
 * Raised by {@see McpSdkClientAdapter} for SDK connection loss during a
 * tool call, or by {@see McpConnectionManager::callTool()} when no live
 * client is available after reconnect. Other call failures propagate
 * unchanged. Callers in the tool
 * layer ({@see \Ineersa\CodingAgent\Mcp\Tool\McpToolInvoker})
 * translate this to an AgentCore {@see \Ineersa\AgentCore\Contract\Tool\ToolCallException}
 * so the existing ToolExecutor pipeline can produce a failed
 * {@see \Ineersa\AgentCore\Contract\Tool\ToolResult}.
 *
 * The tool layer owns retryability and the AgentCore tool-error mapping.
 */
class McpClientInvocationException extends \RuntimeException
{
}
