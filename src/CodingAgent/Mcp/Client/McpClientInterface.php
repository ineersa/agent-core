<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Client;

/**
 * Hatfield-owned MCP client boundary interface.
 *
 * This interface defines the contract that the rest of the Hatfield app
 * uses to interact with MCP servers. It returns only Hatfield-owned or
 * PHP-native types — no `Mcp\*` vendor types leak through this boundary.
 *
 * The concrete implementation {@see McpSdkClientAdapter} wraps the
 * official `mcp/sdk` package and translates between the vendor API
 * and this Hatfield contract.
 *
 * This boundary isolates the app from SDK API churn during pre-1.0
 * development of the `mcp/sdk` package.
 */
interface McpClientInterface
{
    /**
     * Connect to the configured MCP server transport.
     *
     * @throws McpClientConnectionException if connection or initialization fails
     */
    public function connect(): void;

    /**
     * Disconnect from the MCP server and clean up transport resources.
     */
    public function disconnect(): void;

    /**
     * Whether the client is currently connected and initialized.
     */
    public function isConnected(): bool;

    /**
     * List available tools from the connected MCP server.
     *
     * Returns an array of tool definitions as associative arrays,
     * each containing at minimum 'name', 'description', and 'inputSchema' keys.
     *
     * @return list<array{name: string, description?: string|null, inputSchema: array}>
     */
    public function listTools(): array;

    /**
     * Call a tool on the connected MCP server.
     *
     * @param string               $name      Tool name
     * @param array<string, mixed> $arguments Tool arguments
     *
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    public function callTool(string $name, array $arguments = []): array;
}
