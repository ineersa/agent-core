<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Client;

/**
 * Client-layer exception for MCP tool invocation failures.
 *
 * Thrown by {@see McpConnectionManager::callTool()} when the SDK
 * call fails or no live client is available.  Callers in the tool
 * layer ({@see \Ineersa\CodingAgent\Mcp\Tool\McpToolInvoker})
 * translate this to an AgentCore {@see \Ineersa\AgentCore\Contract\Tool\ToolCallException}
 * so the existing ToolExecutor pipeline can produce a failed
 * {@see \Ineersa\AgentCore\Contract\Tool\ToolResult}.
 *
 * This isolation keeps the AppMcpClient layer free of AgentCore
 * imports and respects the Depfile boundary.
 */
final class McpClientInvocationException extends \RuntimeException
{
}
