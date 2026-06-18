<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Client;

use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Psr\Log\LoggerInterface;

/**
 * Broker-owned MCP connection manager.
 *
 * Maintains one {@see McpClientInterface} per (runId, serverName) in the
 * MCP broker process. STDIO servers are session-scoped keep-alive and
 * must never be duplicated across tool workers.
 *
 * This manager lives inside the single mcp Messenger consumer so the
 * "one client per server" invariant is enforced naturally by the process
 * boundary — there is exactly one mcp consumer per controller session.
 *
 * SDK imports are isolated to this namespace and {@see McpSdkClientFactory},
 * preserving the SDK boundary.
 */
class McpConnectionManager
{
    /**
     * Active clients keyed by "runId:serverName".
     *
     * @var array<string, McpClientInterface>
     */
    private array $clients = [];

    public function __construct(
        private readonly McpConfigLoader $configLoader,
        private readonly McpSdkClientFactory $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Discover tools from all enabled MCP servers for a given run.
     *
     * Loads the current MCP config, connects to each enabled server,
     * lists tools, and returns a map of server name → list of tool
     * definitions (as returned by {@see McpClientInterface::listTools()}).
     *
     * On discovery failure for a server, the server is recorded as
     * failed but discovery continues for remaining servers.
     *
     * @param string $runId Session/run identifier
     *
     * @return array<string, array{status: 'connected'|'failed', transport: string, tools: list<array{name: string, description?: string|null, inputSchema: array<string, mixed>}>, errorMessage?: string}>
     */
    public function discover(string $runId): array
    {
        $results = [];

        try {
            $config = $this->configLoader->load();
        } catch (\Throwable $e) {
            $this->logger->warning('MCP config load failed during discovery', [
                'component' => 'mcp',
                'mcp_event' => 'discovery.config_failed',
                'run_id' => $runId,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);

            return $results;
        }

        foreach ($config->servers as $serverName => $server) {
            $clientKey = $this->clientKey($runId, $serverName);

            try {
                // Disconnect any existing client for this run/server before
                // reconnecting — ensures clean state on refresh/resume.
                $this->disconnectServer($runId, $serverName);

                $client = $this->clientFactory->create($server);
                $client->connect();

                $tools = $client->listTools();

                // Keep client alive for future tool calls
                $this->clients[$clientKey] = $client;

                $transport = null !== $server->transportType
                    ? $server->transportType->value
                    : 'unknown';

                $results[$serverName] = [
                    'status' => 'connected',
                    'transport' => $transport,
                    'tools' => $tools,
                ];

                $this->logger->info('MCP server connected and tools discovered', [
                    'component' => 'mcp',
                    'mcp_event' => 'discovery.server_connected',
                    'run_id' => $runId,
                    'server_name' => $serverName,
                    'transport' => $transport,
                    'tool_count' => \count($tools),
                ]);
            } catch (\Throwable $e) {
                // Server discovery failed — log and continue with next server.
                // Never include raw config, env values, headers, or tokens.
                $transport = null !== $server->transportType
                    ? $server->transportType->value
                    : 'unknown';

                $results[$serverName] = [
                    'status' => 'failed',
                    'transport' => $transport,
                    'tools' => [],
                    'errorMessage' => $this->sanitizeErrorMessage($e),
                ];

                $this->logger->warning('MCP server discovery failed', [
                    'component' => 'mcp',
                    'mcp_event' => 'discovery.server_failed',
                    'run_id' => $runId,
                    'server_name' => $serverName,
                    'transport' => $transport,
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Get an already-connected client for a server.
     *
     * Returns null if no connected client exists for this run/server.
     */
    public function getClient(string $runId, string $serverName): ?McpClientInterface
    {
        return $this->clients[$this->clientKey($runId, $serverName)] ?? null;
    }

    /**
     * Disconnect a single server for a run.
     */
    public function disconnectServer(string $runId, string $serverName): void
    {
        $key = $this->clientKey($runId, $serverName);
        if (isset($this->clients[$key])) {
            try {
                $this->clients[$key]->disconnect();
                $this->logger->debug('MCP server disconnected', [
                    'component' => 'mcp',
                    'mcp_event' => 'server.disconnected',
                    'run_id' => $runId,
                    'server_name' => $serverName,
                ]);
            } catch (\Throwable $e) {
                // Disconnect failure is non-fatal — log and continue cleanup.
                $this->logger->warning('MCP server disconnect error', [
                    'component' => 'mcp',
                    'mcp_event' => 'server.disconnect_failed',
                    'run_id' => $runId,
                    'server_name' => $serverName,
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                ]);
            }

            unset($this->clients[$key]);
        }
    }

    /**
     * Disconnect all clients for a given run.
     *
     * Used on session disconnect or graceful shutdown.
     * Individual disconnect failures are logged but do not prevent
     * cleanup of remaining servers.
     */
    public function disconnectAll(string $runId): void
    {
        $prefix = $runId.':';

        foreach ($this->clients as $key => $client) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            $serverName = substr($key, \strlen($prefix));

            try {
                $client->disconnect();
                $this->logger->debug('MCP server disconnected (shutdown)', [
                    'component' => 'mcp',
                    'mcp_event' => 'server.disconnected',
                    'run_id' => $runId,
                    'server_name' => $serverName,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('MCP server disconnect error during shutdown', [
                    'component' => 'mcp',
                    'mcp_event' => 'server.disconnect_failed',
                    'run_id' => $runId,
                    'server_name' => $serverName,
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                ]);
            }

            unset($this->clients[$key]);
        }
    }

    /**
     * Build an internal client-lookup key.
     */
    private function clientKey(string $runId, string $serverName): string
    {
        return $runId.':'.$serverName;
    }

    /**
     * Produce a diagnostic-safe error message from an exception.
     *
     * Truncates long messages and never includes raw command args,
     * env values, headers, or tokens.
     */
    private function sanitizeErrorMessage(\Throwable $e): string
    {
        $msg = $e->getMessage();

        // Truncate to a reasonable diagnostic length
        if (\strlen($msg) > 500) {
            $msg = substr($msg, 0, 497).'...';
        }

        return $msg;
    }
}
