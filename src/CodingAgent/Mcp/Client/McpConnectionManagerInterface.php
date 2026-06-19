<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Client;

/**
 * Hatfield-owned contract for the broker-owned MCP connection manager.
 *
 * Maintains one {@see McpClientInterface} per (runId, serverName) in the
 * MCP broker process. STDIO servers are session-scoped keep-alive and
 * must never be duplicated across tool workers.
 *
 * This interface exists so handlers can depend on an abstraction rather
 * than a concrete class, keeping the concrete manager {@see McpConnectionManager}
 * eligible for `final`.
 */
interface McpConnectionManagerInterface
{
    /**
     * Discover tools from all enabled MCP servers for a given run.
     *
     * Loads the current MCP config, connects to each enabled server,
     * lists tools, and returns a map of server name → discovery result.
     *
     * On discovery failure for a server, the server is recorded as
     * failed but discovery continues for remaining servers.
     *
     * When {@see $onServerDiscovered} is provided, it is called after
     * each server's result is known (connected or failed) with the
     * cumulative discovery results so far.  Callers can use this to
     * publish partial catalogs incrementally so successful servers
     * are visible before slow or failing servers finish.
     *
     * @param string                                                                                                                                                                                                        $runId              Session/run identifier
     * @param ?callable(array<string, array{status: 'connected'|'failed', transport: string, tools: list<array{name: string, description?: string|null, inputSchema: array<string, mixed>}>, errorMessage?: string}>): void $onServerDiscovered Called after each server result is known with cumulative results
     *
     * @return array<string, array{status: 'connected'|'failed', transport: string, tools: list<array{name: string, description?: string|null, inputSchema: array<string, mixed>}>, errorMessage?: string}>
     */
    public function discover(string $runId, ?callable $onServerDiscovered = null): array;

    /**
     * Get an already-connected client for a server.
     *
     * Returns null if no connected client exists for this run/server.
     */
    public function getClient(string $runId, string $serverName): ?McpClientInterface;

    /**
     * Disconnect a single server for a run.
     */
    public function disconnectServer(string $runId, string $serverName): void;

    /**
     * Disconnect all clients for a given run.
     *
     * Used on session disconnect or graceful shutdown.
     * Individual disconnect failures are logged but do not prevent
     * cleanup of remaining servers.
     */
    public function disconnectAll(string $runId): void;

    /**
     * Call a tool on a connected MCP server.
     *
     * If no live client exists for this run/server, attempts reconnect
     * once before failing (does NOT rediscover/listTools).
     *
     * @param string               $runId      Session/run identifier
     * @param string               $serverName MCP server name
     * @param string               $toolName   Raw MCP tool name
     * @param array<string, mixed> $arguments  Tool arguments
     *
     * @return array{content: list<array<string, mixed>>, isError: bool}
     *
     * @throws McpClientInvocationException on missing client after reconnect
     * @throws McpClientInvocationException on SDK call failures
     */
    public function callTool(string $runId, string $serverName, string $toolName, array $arguments = []): array;
}
