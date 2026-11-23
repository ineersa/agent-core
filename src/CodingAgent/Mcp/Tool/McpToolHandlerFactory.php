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
     * @param array<string, mixed>|null $outputSchema
     */
    public function create(string $serverName, string $mcpName, ?array $outputSchema = null): McpToolHandler
    {
        return new McpToolHandler(
            serverName: $serverName,
            mcpName: $mcpName,
            invoker: $this->invoker,
            outputSchema: $outputSchema,
        );
    }
}
