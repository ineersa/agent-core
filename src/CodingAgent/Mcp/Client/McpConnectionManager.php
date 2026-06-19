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
final class McpConnectionManager implements McpConnectionManagerInterface
{
    /**
     * Patterns that may appear in error messages and indicate secret-bearing
     * substrings that must not be logged or stored in catalog error messages.
     *
     * Each entry is [regex flag, 'replacement'].
     *
     * @var list<array{string, string}>
     */
    private const SECRET_PATTERNS = [
        // Redact the entire authorization value (Bearer + token)
        ['/authorization:\s*Bearer\s+\S+/i', 'authorization: Bearer <redacted>'],
        ['/Authorization:\s*Bearer\s+\S+/i', 'Authorization: Bearer <redacted>'],
        // Catch bare Bearer tokens not preceded by Authorization:
        ['/bearer\s+\S+/i', 'bearer <redacted>'],
        // URL query-like secret parameters
        ['/[?&]api_key=\S+/i', 'api_key=<redacted>'],
        ['/[?&]secret=\S+/i', 'secret=<redacted>'],
        ['/[?&]token=\S+/i', 'token=<redacted>'],
        ['/[?&]password=\S+/i', 'password=<redacted>'],
        // Key:value style headers/bodies
        ['/api[-_]?key\s*[:=]\s*\S+/i', 'api_key <redacted>'],
    ];

    /** Maximum allowable length for a sanitized error message. */
    private const MAX_ERROR_MSG_LENGTH = 500;
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
                'error_message' => self::sanitizeLogMessage($e->getMessage()),
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
                    'errorMessage' => self::sanitizeLogMessage($e->getMessage()),
                ];

                $this->logger->warning('MCP server discovery failed', [
                    'component' => 'mcp',
                    'mcp_event' => 'discovery.server_failed',
                    'run_id' => $runId,
                    'server_name' => $serverName,
                    'transport' => $transport,
                    'error_class' => $e::class,
                    'error_message' => self::sanitizeLogMessage($e->getMessage()),
                ]);
            }
        }

        return $results;
    }

    public function getClient(string $runId, string $serverName): ?McpClientInterface
    {
        return $this->clients[$this->clientKey($runId, $serverName)] ?? null;
    }

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
                    'error_message' => self::sanitizeLogMessage($e->getMessage()),
                ]);
            }

            unset($this->clients[$key]);
        }
    }

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
                    'error_message' => self::sanitizeLogMessage($e->getMessage()),
                ]);
            }

            unset($this->clients[$key]);
        }
    }

    /**
     * Produce a diagnostic-safe error message for log/catalog use.
     *
     * Applies truncation and redacts common secret-bearing substrings
     * (bearer tokens, authorization headers, api_key, token, password, secret).
     * Never includes raw command args, env values, headers, or tokens.
     */
    public static function sanitizeLogMessage(string $message): string
    {
        // Truncate to a reasonable diagnostic length
        if (\strlen($message) > self::MAX_ERROR_MSG_LENGTH) {
            $message = substr($message, 0, self::MAX_ERROR_MSG_LENGTH - 3).'...';
        }

        // Redact common secret-bearing patterns
        foreach (self::SECRET_PATTERNS as [$pattern, $replacement]) {
            $message = preg_replace($pattern, $replacement, $message);
        }

        return (string) $message;
    }

    /**
     * {@inheritDoc}
     *
     * TODO: Per-call timeout and cancellation are not enforced because the
     * MCP SDK ({@see McpSdkClientAdapter}) has no per-call timeout or
     * cancellation hook.  Request timeout is fixed at client construction
     * time ({@see McpSdkClientFactory::createSdkClient()}) and cannot be
     * capped by {@see \Ineersa\AgentCore\Application\Tool\ToolContext::timeoutSeconds()}
     * on a per-call basis.  If/when the SDK adds call-level timeout support,
     * wire it through {@see McpClientInterface::callTool()} and resume
     * enforcement here.
     */
    public function callTool(string $runId, string $serverName, string $toolName, array $arguments = []): array
    {
        $client = $this->getClient($runId, $serverName);

        if (null === $client) {
            // Attempt reconnect once before failing
            $this->reconnectServer($runId, $serverName);
            $client = $this->getClient($runId, $serverName);
        }

        if (null === $client) {
            throw new McpClientInvocationException(\sprintf('MCP server "%s" is not connected after reconnect attempt.', $serverName));
        }

        try {
            return $client->callTool($toolName, $arguments);
        } catch (\Throwable $e) {
            // Log with structured context and sanitized message.
            $this->logger->error('MCP tool call failed', [
                'component' => 'mcp',
                'event_type' => 'tool.call_failed',
                'mcp_event' => 'tool.call_failed',
                'run_id' => $runId,
                'session_id' => $runId,
                'server_name' => $serverName,
                'mcp_tool_name' => $toolName,
                'error_class' => $e::class,
                'error_message' => self::sanitizeLogMessage($e->getMessage()),
            ]);

            // Remove the failed client so future attempts trigger reconnect
            $this->disconnectServer($runId, $serverName);

            // Use sanitized error text so secrets/credentials never
            // appear in LLM-visible exception messages.
            throw new McpClientInvocationException(self::sanitizeLogMessage($e->getMessage()), (int) $e->getCode(), $e);
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
     * Reconnect to a single MCP server for a given run.
     *
     * Disconnects any existing client, creates a fresh one,
     * and connects it.  Used as a one-shot recovery when a live
     * client is missing at tool-call time.
     *
     * On failure the old client is cleaned up and the error is
     * logged; callers must check getClient() after calling this.
     */
    private function reconnectServer(string $runId, string $serverName): void
    {
        // Clean up any existing client first
        $this->disconnectServer($runId, $serverName);

        try {
            $config = $this->configLoader->load();
        } catch (\Throwable $e) {
            $this->logger->warning('MCP config load failed during reconnect', [
                'component' => 'mcp',
                'event_type' => 'client.reconnect_config_failed',
                'mcp_event' => 'client.reconnect_config_failed',
                'run_id' => $runId,
                'session_id' => $runId,
                'server_name' => $serverName,
                'error_class' => $e::class,
                'error_message' => self::sanitizeLogMessage($e->getMessage()),
            ]);

            return;
        }

        $server = $config->servers[$serverName] ?? null;
        if (null === $server) {
            $this->logger->warning('MCP server not found in config during reconnect', [
                'component' => 'mcp',
                'event_type' => 'client.reconnect_server_not_found',
                'mcp_event' => 'client.reconnect_server_not_found',
                'run_id' => $runId,
                'session_id' => $runId,
                'server_name' => $serverName,
            ]);

            return;
        }

        try {
            $client = $this->clientFactory->create($server);
            $client->connect();
            $this->clients[$this->clientKey($runId, $serverName)] = $client;

            $this->logger->info('MCP server reconnected for tool call', [
                'component' => 'mcp',
                'event_type' => 'client.reconnected',
                'mcp_event' => 'client.reconnected',
                'run_id' => $runId,
                'session_id' => $runId,
                'server_name' => $serverName,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('MCP server reconnect failed', [
                'component' => 'mcp',
                'event_type' => 'client.reconnect_failed',
                'mcp_event' => 'client.reconnect_failed',
                'run_id' => $runId,
                'session_id' => $runId,
                'server_name' => $serverName,
                'error_class' => $e::class,
                'error_message' => self::sanitizeLogMessage($e->getMessage()),
            ]);
        }
    }
}
