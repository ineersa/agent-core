<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Tool;

use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Config\McpConfigDTO;
use Ineersa\CodingAgent\Mcp\Config\McpServerAvailabilityEnum;

/**
 * Maps session MCP catalogs to runtime tool names by server availability.
 */
final readonly class McpServerToolAvailability
{
    /**
     * @return list<string> hatfield MCP tool names from servers marked availability=all
     */
    public function globalRuntimeToolNames(?McpToolCatalogDTO $catalog, McpConfigDTO $config): array
    {
        return $this->runtimeToolNamesForAvailability($catalog, $config, McpServerAvailabilityEnum::All);
    }

    /**
     * @return list<string> hatfield MCP tool names from servers marked availability=specific
     */
    public function specificRuntimeToolNames(?McpToolCatalogDTO $catalog, McpConfigDTO $config): array
    {
        return $this->runtimeToolNamesForAvailability($catalog, $config, McpServerAvailabilityEnum::Specific);
    }

    /**
     * @return list<string>
     */
    private function runtimeToolNamesForAvailability(
        ?McpToolCatalogDTO $catalog,
        McpConfigDTO $config,
        McpServerAvailabilityEnum $availability,
    ): array {
        if (null === $catalog) {
            return [];
        }

        $names = [];
        foreach ($catalog->servers as $serverEntry) {
            if (McpServerCatalogStatusEnum::CONNECTED !== $serverEntry->status) {
                continue;
            }

            $serverDef = $config->servers[$serverEntry->serverName] ?? null;
            if (null === $serverDef || $serverDef->availability !== $availability) {
                continue;
            }

            foreach ($serverEntry->tools as $toolDef) {
                $names[$toolDef->hatfieldName] = true;
            }
        }

        return array_keys($names);
    }
}
