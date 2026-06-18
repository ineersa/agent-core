<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Handler;

use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogEntryDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolNameMapper;
use Ineersa\CodingAgent\Mcp\Client\McpConnectionManager;
use Ineersa\CodingAgent\Mcp\Config\McpConfigDTO;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Message\McpDisconnectSessionCommand;
use Ineersa\CodingAgent\Mcp\Message\McpInitializeSessionCommand;
use Ineersa\CodingAgent\Mcp\Message\McpRefreshCatalogCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles MCP lifecycle commands on agent.command.bus.
 *
 * MCP-02 Phase 1 behavior (no SDK connections):
 *  - Initialize: load MCP config, log lifecycle event with enabled
 *    server count.  Config failures are warning-only — normal sessions
 *    continue unaffected.
 *  - Refresh catalog / Disconnect: no-op log-only skeletons.
 *
 * MCP-03 Phase 3 behavior (this implementation):
 *  - Initialize: load config, connect to enabled servers via McpConnectionManager,
 *    discover tools, write session catalog snapshot.
 *  - Refresh catalog: full rediscovery via McpConnectionManager, atomically
 *    replace catalog with new snapshot.
 *  - Disconnect: disconnect broker-owned clients for the run.
 *
 * This handler runs in the mcp consumer (controller mode, Doctrine
 * transport) or inline on the command bus (TUI/sync mode).  In TUI/sync
 * mode, discovery runs synchronously — acceptable for the local session.
 */
#[AsMessageHandler(bus: 'agent.command.bus')]
final class McpInitializeSessionHandler
{
    public function __construct(
        private readonly McpConfigLoader $configLoader,
        private readonly McpConnectionManager $connectionManager,
        private readonly McpToolNameMapper $nameMapper,
        private readonly McpToolCatalogStoreInterface $catalogStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle MCP session initialization.
     *
     * Loads MCP config, connects to each enabled server, discovers
     * tools, and writes the session catalog snapshot.
     *
     * Config loading / server discovery failures are intentionally
     * caught and logged as warnings — MCP is optional infrastructure
     * and must not disrupt normal agent sessions.
     *
     * On config failure, an empty catalog is written to invalidate
     * any stale previous catalog for this run.
     */
    public function __invoke(McpInitializeSessionCommand $message): void
    {
        $logContext = [
            'component' => 'mcp',
            'mcp_event' => 'session.initialize',
            'run_id' => $message->runId,
            'session_id' => $message->runId,
            'reason' => $message->reason,
            'correlation_id' => '' !== $message->correlationId ? $message->correlationId : null,
        ];

        try {
            // Load config to compute configHash for invalidation.
            // If config load fails, write an empty/failed catalog so stale
            // tools from any previous successful discovery are NOT retained.
            $config = $this->configLoader->load();
            $enabledCount = \count($config->servers);
            $configHash = $this->computeConfigHash($config);

            $this->logger->info('MCP session initialize', [
                ...$logContext,
                'enabled_server_count' => $enabledCount,
            ]);

            if ($enabledCount > 0) {
                $discoveryResults = $this->connectionManager->discover($message->runId);
                $catalog = $this->buildCatalog($message->runId, $configHash, $discoveryResults);
            } else {
                // No servers configured — write empty catalog.
                $catalog = McpToolCatalogDTO::empty($message->runId, 1, $configHash);
            }

            $this->catalogStore->write($message->runId, $catalog);

            $this->logger->info('MCP catalog written', [
                'component' => 'mcp',
                'mcp_event' => 'catalog.written',
                'run_id' => $message->runId,
                'server_count' => \count($catalog->servers),
                'tool_count' => $this->countTools($catalog),
                'generation' => $catalog->generation,
            ]);
        } catch (\Throwable $e) {
            // Config loading / validation / interpolation / discovery
            // failure is non-fatal. Write an empty catalog to invalidate
            // any previously-discovered tools, then log a warning with
            // the exception class and message only — never dump raw
            // config, env values, headers, or tokens.
            try {
                $this->catalogStore->write(
                    $message->runId,
                    McpToolCatalogDTO::empty($message->runId, 1),
                );
            } catch (\Throwable) {
                // Best-effort — if we cannot even write an empty catalog,
                // there is nothing more we can do for this run.
            }

            $this->logger->warning('MCP initialize failed — config or discovery error, continuing without MCP', [
                ...$logContext,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle catalog refresh — full rediscovery and snapshot replacement.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onRefreshCatalog(McpRefreshCatalogCommand $message): void
    {
        $logContext = [
            'component' => 'mcp',
            'mcp_event' => 'catalog.refresh',
            'run_id' => $message->runId,
            'correlation_id' => '' !== $message->correlationId ? $message->correlationId : null,
        ];

        try {
            $config = $this->configLoader->load();
            $enabledCount = \count($config->servers);
            $configHash = $this->computeConfigHash($config);

            if ($enabledCount > 0) {
                $discoveryResults = $this->connectionManager->discover($message->runId);
                $catalog = $this->buildCatalog($message->runId, $configHash, $discoveryResults);
            } else {
                $catalog = McpToolCatalogDTO::empty($message->runId, 1, $configHash);
            }

            $this->catalogStore->write($message->runId, $catalog);

            $this->logger->info('MCP catalog refreshed', [
                ...$logContext,
                'server_count' => \count($catalog->servers),
                'tool_count' => $this->countTools($catalog),
                'generation' => $catalog->generation,
            ]);
        } catch (\Throwable $e) {
            // Refresh failure is non-fatal — log and continue.
            // Do NOT overwrite catalog on refresh failure to avoid losing
            // the previously-working catalog.
            $this->logger->warning('MCP catalog refresh failed', [
                ...$logContext,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle session disconnect — disconnect broker-owned clients.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onDisconnectSession(McpDisconnectSessionCommand $message): void
    {
        $this->logger->debug('MCP session disconnect — closing broker clients', [
            'component' => 'mcp',
            'mcp_event' => 'session.disconnect',
            'run_id' => $message->runId,
            'correlation_id' => '' !== $message->correlationId ? $message->correlationId : null,
        ]);

        $this->connectionManager->disconnectAll($message->runId);
    }

    /**
     * Build the catalog DTO from raw discovery results.
     *
     * Applies tool name mapping (server_tool), include/exclude filters,
     * and builds server entry DTOs with appropriate statuses.
     *
     * @param array<string, array{status: 'connected'|'failed', transport: string, tools: list<array>, errorMessage?: string}> $discoveryResults
     */
    private function buildCatalog(string $runId, ?string $configHash, array $discoveryResults): McpToolCatalogDTO
    {
        $servers = [];
        $generation = 1;

        // Load config for include/exclude tool filters
        try {
            $config = $this->configLoader->load();
        } catch (\Throwable) {
            $config = null;
        }

        foreach ($discoveryResults as $serverName => $result) {
            $excludeTools = [];
            if (null !== $config && isset($config->servers[$serverName])) {
                $excludeTools = $config->servers[$serverName]->excludeTools;
            }

            if ('connected' === $result['status']) {
                $tools = $this->mapTools($serverName, $result['tools'], $excludeTools);

                $servers[$serverName] = new McpServerCatalogEntryDTO(
                    serverName: $serverName,
                    transport: $result['transport'],
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: $tools,
                );
            } else {
                // Failed server — record with no tools and diagnostic-safe error
                $servers[$serverName] = new McpServerCatalogEntryDTO(
                    serverName: $serverName,
                    transport: $result['transport'],
                    status: McpServerCatalogStatusEnum::FAILED,
                    errorMessage: $result['errorMessage'] ?? 'Unknown discovery error',
                    tools: [],
                );
            }
        }

        return new McpToolCatalogDTO(
            schemaVersion: 1,
            runId: $runId,
            generatedAt: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            generation: $generation,
            configHash: $configHash,
            servers: $servers,
        );
    }

    /**
     * Map raw server tools to Hatfield-namespaced tool definitions.
     *
     * Apply exclude filter if provided. Detect and log duplicate
     * mapped names within the same server.
     *
     * @param list<array{name: string, description?: string|null, inputSchema: array<string, mixed>}> $rawTools
     * @param list<string>                                                                            $excludeTools
     *
     * @return list<McpToolDefinitionDTO>
     */
    private function mapTools(string $serverName, array $rawTools, array $excludeTools): array
    {
        $tools = [];
        $seenNames = [];

        foreach ($rawTools as $raw) {
            $mcpName = $raw['name'] ?? '';
            if ('' === $mcpName) {
                continue;
            }

            // Exclude filter
            if (\in_array($mcpName, $excludeTools, true)) {
                $this->logger->debug('MCP tool excluded by filter', [
                    'component' => 'mcp',
                    'mcp_event' => 'tool.excluded',
                    'server_name' => $serverName,
                    'mcp_tool_name' => $mcpName,
                ]);
                continue;
            }

            $hatfieldName = $this->nameMapper->mapHatfieldName($serverName, $mcpName);

            // Duplicate detection within the same catalog
            if (isset($seenNames[$hatfieldName])) {
                $this->logger->warning('MCP tool name collision — skipping duplicate', [
                    'component' => 'mcp',
                    'mcp_event' => 'tool.duplicate',
                    'server_name' => $serverName,
                    'hatfield_name' => $hatfieldName,
                    'mcp_tool_name' => $mcpName,
                ]);
                continue;
            }

            $seenNames[$hatfieldName] = true;

            $tools[] = new McpToolDefinitionDTO(
                hatfieldName: $hatfieldName,
                serverName: $serverName,
                mcpName: $mcpName,
                description: (string) ($raw['description'] ?? ''),
                inputSchema: $raw['inputSchema'] ?? [],
            );
        }

        return $tools;
    }

    /**
     * Count total tools across all server entries in a catalog.
     */
    private function countTools(McpToolCatalogDTO $catalog): int
    {
        $count = 0;
        foreach ($catalog->servers as $entry) {
            $count += \count($entry->tools);
        }

        return $count;
    }

    /**
     * Compute a short hash of the merged MCP config for catalog invalidation.
     *
     * This is a fast non-cryptographic fingerprint — used only to detect
     * config changes between discovery generations.
     */
    private function computeConfigHash(McpConfigDTO $config): ?string
    {
        try {
            $serversHash = [];
            foreach ($config->servers as $name => $server) {
                // Hash only the config-relevant fields, not runtime state.
                // Exclude timeoutMs/startupTimeoutMs because those are
                // operational parameters, not identity-changing values.
                $fields = [
                    'name' => $server->name,
                    'enabled' => $server->enabled,
                    'transport' => $server->transportType?->value,
                ];
                $serversHash[$name] = hash('sha256', json_encode($fields, \JSON_THROW_ON_ERROR));
            }

            return hash('sha256', json_encode($serversHash, \JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return null;
        }
    }
}
