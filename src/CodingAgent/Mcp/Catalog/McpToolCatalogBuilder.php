<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Catalog;

use Ineersa\CodingAgent\Mcp\Config\McpConfigDTO;
use Psr\Log\LoggerInterface;

/**
 * Builds MCP tool catalogs from raw discovery results.
 *
 * Owns the catalog-construction policy that was previously embedded in
 * {@see \Ineersa\CodingAgent\Mcp\Handler\McpInitializeSessionHandler}:
 * tool name mapping, include/exclude filters, cross-server duplicate
 * detection, and config hashing. The lifecycle handler keeps discovery,
 * partial/final store writes, failure invalidation, and logging.
 */
final class McpToolCatalogBuilder
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Build the catalog DTO from raw discovery results.
     *
     * Applies tool name mapping (server_tool), include/exclude filters,
     * and cross-catalog duplicate detection.
     *
     * @param array<string, array{status: 'connected'|'failed', transport: string, tools: list<array>, errorMessage?: string}> $discoveryResults
     */
    public function build(McpConfigDTO $config, string $runId, ?string $configHash, array $discoveryResults): McpToolCatalogDTO
    {
        $servers = [];
        $globalSeenNames = [];

        foreach ($discoveryResults as $serverName => $result) {
            $excludeTools = [];
            if (isset($config->servers[$serverName])) {
                $excludeTools = $config->servers[$serverName]->excludeTools;
            }

            if ('connected' === $result['status']) {
                $tools = $this->mapTools($serverName, $result['tools'], $excludeTools, $globalSeenNames, $runId);

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
            generation: 1,
            configHash: $configHash,
            servers: $servers,
        );
    }

    /**
     * Compute a short hash of the merged MCP config for catalog invalidation.
     *
     * Includes all discovery-affecting fields so that URL, command, args,
     * cwd, excludeTools, and env/header keys changes produce a new hash.
     *
     * Env/header keys are included for change detection, but values are
     * hashed (SHA-256) before inclusion to avoid storing raw env values
     * in the catalog.  The hash itself is a one-way digest — it reveals
     * no secrets even if stored in plain text.
     */
    public function computeConfigHash(McpConfigDTO $config): ?string
    {
        try {
            $serversHash = [];

            foreach ($config->servers as $name => $server) {
                $fields = [
                    'name' => $server->name,
                    'enabled' => $server->enabled,
                    'transport' => $server->transportType?->value,
                    'command' => $server->command,
                    'args' => $server->args,
                    'cwd' => $server->cwd,
                    'url' => $server->url,
                    'timeoutMs' => $server->timeoutMs,
                    'startupTimeoutMs' => $server->startupTimeoutMs,
                    'excludeTools' => $server->excludeTools,
                    // Include keys only (not values) for change detection
                    // on env/headers — values are hashed separately.
                    'envKeys' => array_keys($server->env),
                    'envValuesHash' => [] !== $server->env
                        ? hash('sha256', json_encode($server->env, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES))
                        : null,
                    'headerKeys' => array_keys($server->headers),
                    'headerValuesHash' => [] !== $server->headers
                        ? hash('sha256', json_encode($server->headers, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES))
                        : null,
                ];

                $serversHash[$name] = hash(
                    'sha256',
                    json_encode($fields, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
                );
            }

            // Sort by key for deterministic hash
            ksort($serversHash);

            return hash('sha256', json_encode($serversHash, \JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Map raw server tools to Hatfield-namespaced tool definitions.
     *
     * Apply exclude filter if provided. Detect and log duplicate
     * mapped names across the entire catalog (cross-server), skipping
     * duplicates and logging a warning.
     *
     * @param list<array{name: string, description?: string|null, inputSchema: array<string, mixed>}> $rawTools
     * @param list<string>                                                                            $excludeTools
     * @param array<string, true>                                                                     $globalSeenNames Mutable cross-server duplicate tracker
     *
     * @return list<McpToolDefinitionDTO>
     */
    private function mapTools(string $serverName, array $rawTools, array $excludeTools, array &$globalSeenNames, string $runId): array
    {
        $tools = [];

        foreach ($rawTools as $raw) {
            $mcpName = $raw['name'] ?? '';
            if ('' === $mcpName) {
                continue;
            }

            // Exclude filter
            if (\in_array($mcpName, $excludeTools, true)) {
                $this->logger->debug('MCP tool excluded by filter', [
                    'component' => 'mcp',
                    'event_type' => 'tool.excluded',
                    'mcp_event' => 'tool.excluded',
                    'run_id' => $runId,
                    'session_id' => $runId,
                    'server_name' => $serverName,
                    'mcp_tool_name' => $mcpName,
                ]);
                continue;
            }

            $hatfieldName = $this->mapHatfieldName($serverName, $mcpName);

            // Cross-catalog duplicate detection — sanitized names
            // from different servers can collide (e.g. "a.b/tool" and
            // "a_b/tool" both sanitize to "a_b_tool").
            if (isset($globalSeenNames[$hatfieldName])) {
                $this->logger->warning('MCP tool name collision — skipping duplicate', [
                    'component' => 'mcp',
                    'event_type' => 'tool.duplicate',
                    'mcp_event' => 'tool.duplicate',
                    'run_id' => $runId,
                    'session_id' => $runId,
                    'server_name' => $serverName,
                    'hatfield_name' => $hatfieldName,
                    'mcp_tool_name' => $mcpName,
                ]);
                continue;
            }

            $globalSeenNames[$hatfieldName] = true;

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
     * Map a single tool to its Hatfield name: `{server}_{tool}` after sanitization.
     */
    private function mapHatfieldName(string $serverName, string $mcpToolName): string
    {
        return $this->sanitizeToolNameComponent($serverName).'_'.$this->sanitizeToolNameComponent($mcpToolName);
    }

    /**
     * Sanitize a name component for use in LLM tool identifiers.
     *
     * Replacement rules:
     *  - Allow: a-z, A-Z, 0-9, underscore, hyphen
     *  - Replace any other character with underscore
     *  - Collapse consecutive underscores
     *  - Trim leading/trailing underscores
     *  - Ensure non-empty result
     */
    private function sanitizeToolNameComponent(string $name): string
    {
        if ('' === $name) {
            return 'unknown';
        }

        $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);

        if (null === $sanitized || '' === $sanitized) {
            return 'unknown';
        }

        // Collapse consecutive underscores
        $sanitized = preg_replace('/_+/', '_', $sanitized);

        // Trim leading/trailing underscores
        $sanitized = trim($sanitized, '_');

        if ('' === $sanitized) {
            return 'unknown';
        }

        return $sanitized;
    }
}
