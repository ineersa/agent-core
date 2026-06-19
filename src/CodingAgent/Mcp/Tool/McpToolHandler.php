<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;

/**
 * Deferred MCP tool execution handler.
 *
 * Stores the originating server and tool names for reverse mapping
 * (visible to tool workers and diagnostics).  Actual MCP request/reply
 * invocation is owned by MCP-05 — this handler throws a structured
 * ToolCallException so an LLM calling an MCP tool before MCP-05 is
 * wired receives a clear, safe diagnostic instead of a silent success
 * or opaque crash.
 *
 * The error message intentionally excludes raw arguments and server
 * configuration details to avoid leaking them into LLM-visible output.
 */
final readonly class McpToolHandler implements ToolHandlerInterface
{
    public function __construct(
        public string $serverName,
        public string $mcpName,
    ) {
    }

    public function __invoke(array $arguments): mixed
    {
        throw new ToolCallException(error: \sprintf('MCP tool "%s" (server "%s") is not yet available for invocation. MCP tool execution will be enabled in a future update.', $this->mcpName, $this->serverName), retryable: false, hint: 'MCP tool execution support is planned but not yet implemented. Use built-in tools instead.');
    }
}
