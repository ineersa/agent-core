<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Tool;

use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;

/**
 * Hides MCP tools from availability=specific servers on parent/main runs.
 *
 * Child agent runs pass through unchanged; {@see SubagentToolSetResolver}
 * intersects the active toolset with per-child allowed_tools afterward.
 */
final readonly class McpParentAvailabilityToolSetResolver implements ToolSetResolverInterface
{
    public function __construct(
        private ToolSetResolverInterface $inner,
        private SubagentRunMetadataReader $metadataReader,
        private McpToolCatalogStoreInterface $catalogStore,
        private McpConfigLoader $configLoader,
        private McpServerToolAvailability $availability,
    ) {
    }

    public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
    {
        $inner = $this->inner->resolve($toolsRef, $turnNo, $runId);

        if (null === $runId || '' === $runId) {
            return $inner;
        }

        if ($this->metadataReader->isAgentChild($runId)) {
            return $inner;
        }

        $catalogRunId = $this->resolveCatalogRunId($runId);
        $config = $this->configLoader->load();
        $catalog = $this->catalogStore->read($catalogRunId);
        $hidden = $this->availability->specificRuntimeToolNames($catalog, $config);
        if ([] === $hidden) {
            return $inner;
        }

        $hiddenLookup = array_flip($hidden);

        $toolNames = array_values(array_filter(
            $inner->toolNames,
            static fn (string $name): bool => !isset($hiddenLookup[$name]),
        ));

        $allowList = array_values(array_filter(
            $inner->allowListNames,
            static fn (string $name): bool => !isset($hiddenLookup[$name]),
        ));

        $executionModes = [];
        foreach ($inner->executionModes as $toolName => $mode) {
            if (!isset($hiddenLookup[$toolName])) {
                $executionModes[$toolName] = $mode;
            }
        }

        return new ActiveToolSet(
            toolNames: $toolNames,
            allowListNames: $allowList,
            executionModes: $executionModes,
        );
    }

    private function resolveCatalogRunId(string $runId): string
    {
        $parentRunId = $this->metadataReader->readParentRunId($runId);
        if (null !== $parentRunId) {
            return $parentRunId;
        }

        return $runId;
    }
}
