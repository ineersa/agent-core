<?php

declare(strict_types=1);

namespace Ineersa\Tui\CompactHeader;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDiscovery;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Config\McpServerAvailabilityEnum;
use Ineersa\CodingAgent\Runtime\Contract\PromptTemplateCatalogInterface;
use Ineersa\CodingAgent\Skills\SkillDiscovery;

final class CompactHeaderSnapshotProvider
{
    public function __construct(
        private readonly PromptTemplateCatalogInterface $promptTemplates,
        private readonly SkillDiscovery $skillDiscovery,
        private readonly AgentDefinitionDiscovery $agentDefinitionDiscovery,
        private readonly McpToolCatalogStoreInterface $catalogStore,
        private readonly McpConfigLoader $mcpConfigLoader,
    ) {
    }

    public function build(string $sessionId): CompactHeaderSnapshot
    {
        $prompts = [];
        foreach ($this->promptTemplates->allPromptTemplateCommands() as $template) {
            $prompts[] = $template->name;
        }
        sort($prompts, \SORT_STRING);

        $skills = [];
        foreach ($this->skillDiscovery->discover() as $skill) {
            $skills[] = $skill->name;
        }
        sort($skills, \SORT_STRING);

        $catalog = $this->agentDefinitionDiscovery->discover();
        $enabled = $catalog->enabled();
        $agentNames = array_map(static fn ($d) => $d->name, $enabled);
        sort($agentNames, \SORT_STRING);

        $mcpServers = [];
        // Draft sessions (empty run id) have no MCP catalog yet; skip the read.
        $mcpDto = '' !== $sessionId ? $this->catalogStore->read($sessionId) : null;

        $availByName = [];
        try {
            $config = $this->mcpConfigLoader->load();
            foreach ($config->servers as $name => $def) {
                $availByName[$name] = $def->availability;
            }
        } catch (\Throwable) {
            $availByName = [];
        }

        if (null !== $mcpDto) {
            $entries = $mcpDto->servers;
            ksort($entries, \SORT_STRING);
            foreach ($entries as $entry) {
                $toolCount = \count($entry->tools);
                $isConnected = McpServerCatalogStatusEnum::CONNECTED === $entry->status;
                // Servers absent from config are treated as globally available (always-loaded).
                $isGlobal = ($availByName[$entry->serverName] ?? McpServerAvailabilityEnum::All) === McpServerAvailabilityEnum::All;
                $mcpServers[] = new McpServerHeaderEntry(
                    name: $entry->serverName,
                    toolCount: $isConnected ? $toolCount : null,
                    isConnected: $isConnected,
                    isGlobal: $isGlobal,
                );
            }
        }

        return new CompactHeaderSnapshot(
            prompts: $prompts,
            skills: $skills,
            agentNames: $agentNames,
            mcpServers: $mcpServers,
        );
    }
}
