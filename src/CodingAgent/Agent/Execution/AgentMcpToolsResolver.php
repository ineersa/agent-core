<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Config\McpConfigDTO;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Config\McpServerAvailabilityEnum;

/**
 * Resolves frontmatter `mcp:` selectors and default MCP inheritance into runtime tool names.
 */
final readonly class AgentMcpToolsResolver
{
    private const MCP_SELECTOR_PREFIX = 'mcp:';

    public function __construct(
        private McpToolCatalogStoreInterface $catalogStore,
        private McpConfigLoader $configLoader,
    ) {
    }

    /**
     * @param list<string>|null $tools Agent definition tools (null = omitted)
     *
     * @return array{
     *     non_mcp_tools: list<string>,
     *     mcp_runtime_tools: list<string>,
     *     mcp_policy: array{mode: string, tools: list<string>}
     * }
     */
    public function resolve(?array $tools, string $catalogRunId): array
    {
        $config = $this->configLoader->load();
        $catalog = $this->catalogStore->read($catalogRunId);
        $exposedByServer = $this->indexExposedToolsByServer($catalog, $config);
        $allExposed = $this->flattenExposed($exposedByServer);
        $globalExposed = $this->flattenExposed(
            $this->filterServersByAvailability($exposedByServer, $config, McpServerAvailabilityEnum::All),
        );

        if (null === $tools) {
            return [
                'non_mcp_tools' => [],
                'mcp_runtime_tools' => $globalExposed,
                'mcp_policy' => [
                    'mode' => 'inherited_global',
                    'tools' => $globalExposed,
                ],
            ];
        }

        $nonMcp = [];
        $selectors = [];
        foreach ($tools as $entry) {
            if (str_starts_with($entry, self::MCP_SELECTOR_PREFIX)) {
                $selectors[] = $entry;
                continue;
            }
            $nonMcp[] = $entry;
        }

        if ([] === $selectors) {
            return [
                'non_mcp_tools' => $nonMcp,
                'mcp_runtime_tools' => [],
                'mcp_policy' => [
                    'mode' => 'none',
                    'tools' => [],
                ],
            ];
        }

        $mcpTools = $this->expandSelectors($selectors, $allExposed);

        return [
            'non_mcp_tools' => $nonMcp,
            'mcp_runtime_tools' => $mcpTools,
            'mcp_policy' => [
                'mode' => $this->policyModeFromSelectors($selectors),
                'tools' => $mcpTools,
            ],
        ];
    }

    /**
     * @param list<string> $selectors
     * @param list<string> $allExposed
     *
     * @return list<string>
     */
    private function expandSelectors(array $selectors, array $allExposed): array
    {
        if (\in_array('mcp:-', $selectors, true)) {
            return [];
        }
        if (\in_array('mcp:*', $selectors, true)) {
            return $allExposed;
        }

        $allowed = [];
        foreach ($selectors as $selector) {
            $value = substr($selector, \strlen(self::MCP_SELECTOR_PREFIX));
            if ('-' === $value || '*' === $value) {
                continue;
            }
            if (str_ends_with($value, '_')) {
                foreach ($allExposed as $name) {
                    if (str_starts_with($name, $value)) {
                        $allowed[$name] = true;
                    }
                }
                continue;
            }
            if (\in_array($value, $allExposed, true)) {
                $allowed[$value] = true;
            }
        }

        return array_values(array_keys($allowed));
    }

    /**
     * @param list<string> $selectors
     */
    private function policyModeFromSelectors(array $selectors): string
    {
        if (\in_array('mcp:*', $selectors, true)) {
            return 'all';
        }
        if (\in_array('mcp:-', $selectors, true)) {
            return 'none';
        }

        return 'specific';
    }

    /**
     * @return array<string, list<string>>
     */
    private function indexExposedToolsByServer(?McpToolCatalogDTO $catalog, McpConfigDTO $config): array
    {
        if (null === $catalog) {
            return [];
        }

        $byServer = [];
        foreach ($catalog->servers as $serverEntry) {
            if (McpServerCatalogStatusEnum::CONNECTED !== $serverEntry->status) {
                continue;
            }
            if (!isset($config->servers[$serverEntry->serverName])) {
                continue;
            }
            $names = [];
            foreach ($serverEntry->tools as $toolDef) {
                $names[] = $toolDef->hatfieldName;
            }
            $byServer[$serverEntry->serverName] = $names;
        }

        return $byServer;
    }

    /**
     * @param array<string, list<string>> $byServer
     *
     * @return list<string>
     */
    private function flattenExposed(array $byServer): array
    {
        $all = [];
        foreach ($byServer as $names) {
            foreach ($names as $name) {
                $all[$name] = true;
            }
        }

        return array_values(array_keys($all));
    }

    /**
     * @param array<string, list<string>> $byServer
     *
     * @return array<string, list<string>>
     */
    private function filterServersByAvailability(
        array $byServer,
        McpConfigDTO $config,
        McpServerAvailabilityEnum $availability,
    ): array {
        $filtered = [];
        foreach ($byServer as $serverName => $names) {
            $server = $config->servers[$serverName] ?? null;
            if (null === $server) {
                continue;
            }
            if ($server->availability === $availability) {
                $filtered[$serverName] = $names;
            }
        }

        return $filtered;
    }
}
