<?php

declare(strict_types=1);

namespace Ineersa\Tui\CompactHeader;

use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;

/**
 * Builds /mcp status snapshots from mcp.json + session mcp-tools.json.
 *
 * Lives in CompactHeader because that layer already has Deptrac permission
 * to read AppMcpCatalog / AppMcpConfig; TuiListener must not.
 */
final class McpStatusSnapshotProvider
{
    public function __construct(
        private readonly McpToolCatalogStoreInterface $catalogStore,
        private readonly McpConfigLoader $mcpConfigLoader,
    ) {
    }

    public function build(string $sessionId): McpStatusSnapshot
    {
        try {
            $config = $this->mcpConfigLoader->load();
        } catch (\Throwable $e) {
            $message = trim($e->getMessage());
            if ('' === $message) {
                $message = $e::class;
            }

            return new McpStatusSnapshot(
                servers: [],
                configError: $e::class.': '.$message,
            );
        }

        $catalog = '' !== $sessionId ? $this->catalogStore->read($sessionId) : null;
        $catalogServers = null === $catalog ? [] : $catalog->servers;

        $names = array_keys($config->servers);
        sort($names, \SORT_STRING);

        $views = [];
        foreach ($names as $name) {
            $def = $config->servers[$name];
            $transport = null !== $def->transportType ? $def->transportType->value : 'unknown';
            $entry = $catalogServers[$name] ?? null;

            if (null === $entry) {
                $views[] = new McpServerStatusView(
                    name: $name,
                    transport: $transport,
                    status: 'not_initialized',
                );
                continue;
            }

            if (McpServerCatalogStatusEnum::CONNECTED === $entry->status) {
                $toolNames = array_map(
                    static fn (McpToolDefinitionDTO $tool): string => $tool->hatfieldName,
                    $entry->tools,
                );
                $views[] = new McpServerStatusView(
                    name: $name,
                    transport: $transport,
                    status: 'connected',
                    toolNames: $toolNames,
                );
                continue;
            }

            $views[] = new McpServerStatusView(
                name: $name,
                transport: $transport,
                status: 'failed',
                errorMessage: $entry->errorMessage,
            );
        }

        return new McpStatusSnapshot(
            servers: $views,
            generation: null === $catalog ? 0 : $catalog->generation,
            generatedAt: null === $catalog ? '' : $catalog->generatedAt,
        );
    }
}
