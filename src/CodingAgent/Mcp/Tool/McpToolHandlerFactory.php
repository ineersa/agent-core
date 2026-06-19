<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Tool;

/**
 * Creates per-tool {@see McpToolHandler} instances carrying static
 * MCP identity (serverName, mcpName) and a shared {@see McpToolInvoker}.
 *
 * This factory exists because McpToolHandler has scalar per-tool
 * identity fields and cannot be autowired as a generic service.
 *
 * Handlers produced by this factory are tiny callables that delegate
 * to the shared invoker at runtime.
 */
final class McpToolHandlerFactory
{
    public function __construct(
        private readonly McpToolInvoker $invoker,
    ) {
    }

    /**
     * Create a tool handler for a specific MCP server/tool.
     */
    public function create(string $serverName, string $mcpName): McpToolHandler
    {
        return new McpToolHandler(
            serverName: $serverName,
            mcpName: $mcpName,
            invoker: $this->invoker,
        );
    }
}
