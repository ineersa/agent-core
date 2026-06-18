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
     * @param string $runId Session/run identifier
     *
     * @return array<string, array{status: 'connected'|'failed', transport: string, tools: list<array{name: string, description?: string|null, inputSchema: array<string, mixed>}>, errorMessage?: string}>
     */
    public function discover(string $runId): array;

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
}
