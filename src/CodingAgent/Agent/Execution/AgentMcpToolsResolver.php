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
     *     catalog_mcp_runtime_tools: list<string>,
     *     mcp_policy: array{mode: string, tools: list<string>}
     * }
     */
    public function resolve(?array $tools, string $catalogRunId): array
    {
        $config = $this->configLoader->load();
        $catalog = $this->catalogStore->read($catalogRunId);
        $exposedByServer = $this->indexExposedToolsByServer($catalog, $config);
        // Catalog-advertised Hatfield runtime names from connected servers with config.
        // Child inheritance strips these before re-adding only the selected MCP set. If MCP
        // registration was skipped because a permanent/unrelated dynamic tool already owns the
        // name, that name is still treated as MCP here so it cannot leak via omitted-tools inherit.
        $allExposed = $this->flattenExposed($exposedByServer);
        $globalExposed = $this->flattenExposed(
            $this->filterServersByAvailability($exposedByServer, $config, McpServerAvailabilityEnum::All),
        );

        if (null === $tools) {
            return [
                'non_mcp_tools' => [],
                'mcp_runtime_tools' => $globalExposed,
                'catalog_mcp_runtime_tools' => $allExposed,
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

            // Treat every exact catalog runtime name as MCP-owned, even when it is listed
            // without `mcp:`. This prevents raw global or specific names from bypassing policy.
            // The name-based filtering has the same collision-safety trade-off as omitted-tool
            // inheritance: a permanent/unrelated dynamic tool with a catalog name is classified
            // as MCP for policy purposes.
            if (!\in_array($entry, $allExposed, true)) {
                $nonMcp[] = $entry;
            }
        }

        if ([] === $selectors) {
            return [
                'non_mcp_tools' => $nonMcp,
                'mcp_runtime_tools' => $globalExposed,
                'catalog_mcp_runtime_tools' => $allExposed,
                'mcp_policy' => [
                    'mode' => 'inherited_global',
                    'tools' => $globalExposed,
                ],
            ];
        }

        $policyMode = $this->policyModeFromSelectors($selectors);
        $mcpTools = match ($policyMode) {
            'none' => [],
            // `all` means all globally available MCP tools; specific tools require selectors.
            'all' => $globalExposed,
            'specific' => $globalExposed,
        };
        if ('specific' === $policyMode) {
            $specificSelectors = array_values(array_filter(
                $selectors,
                static fn (string $selector): bool => 'mcp:*' !== $selector && 'mcp:-' !== $selector,
            ));
            foreach ($this->expandSelectors($specificSelectors, $allExposed) as $selectedTool) {
                if (!\in_array($selectedTool, $mcpTools, true)) {
                    $mcpTools[] = $selectedTool;
                }
            }
        }

        return [
            'non_mcp_tools' => $nonMcp,
            'mcp_runtime_tools' => $mcpTools,
            'catalog_mcp_runtime_tools' => $allExposed,
            'mcp_policy' => [
                'mode' => $policyMode,
                'tools' => $mcpTools,
            ],
        ];
    }

    /**
     * Expand specific `mcp:` selectors against catalog-exposed Hatfield runtime names.
     *
     * The caller must invoke this only for `specific` policy mode; `mcp:-` and `mcp:*`
     * are filtered before this method is called.
     *
     * Grammar invariants:
     *  - Exactly one terminal `*` is the only prefix wildcard (`mcp:websearch_*` → names starting with `websearch_`).
     *  - A selector with no `*` is always exact, including names that end with `_`.
     *  - Embedded or multiple `*` characters are not globs; they fall through to exact match (normally no catalog hit).
     *
     * @param list<string> $selectors
     * @param list<string> $allExposed
     *
     * @return list<string>
     */
    private function expandSelectors(array $selectors, array $allExposed): array
    {
        $allowed = [];
        foreach ($selectors as $selector) {
            $value = substr($selector, \strlen(self::MCP_SELECTOR_PREFIX));

            // Prefix wildcard: exactly one star, and it must be terminal (`mcp:<prefix*>`).
            if (str_ends_with($value, '*') && 1 === substr_count($value, '*')) {
                $prefix = substr($value, 0, -1);
                foreach ($allExposed as $name) {
                    if (str_starts_with($name, $prefix)) {
                        $allowed[$name] = true;
                    }
                }
                continue;
            }

            // Exact match only (including trailing `_`, embedded/multiple stars, etc.).
            if (\in_array($value, $allExposed, true)) {
                $allowed[$value] = true;
            }
        }

        return array_keys($allowed);
    }

    /**
     * @param list<string> $selectors
     *
     * @return 'none'|'all'|'specific'
     */
    private function policyModeFromSelectors(array $selectors): string
    {
        if (\in_array('mcp:-', $selectors, true)) {
            return 'none';
        }

        foreach ($selectors as $selector) {
            if ('mcp:*' !== $selector) {
                return 'specific';
            }
        }

        return 'all';
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
     * Flatten per-server Hatfield names into a single list, deduplicating by runtime name
     * while preserving first-seen order across servers.
     *
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

        return array_keys($all);
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
