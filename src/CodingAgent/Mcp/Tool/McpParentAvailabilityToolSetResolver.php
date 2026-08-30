<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Tool;

use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface;

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
        private RunRelationshipReaderInterface $relationshipReader,
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

        try {
            if ($this->relationshipReader->isAgentChild($runId)) {
                return $inner;
            }
        } catch (\RuntimeException) {
            // Unknown identity: do not apply parent-only MCP availability filtering.
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

        $timeoutSeconds = [];
        foreach ($inner->timeoutSeconds as $toolName => $seconds) {
            if (!isset($hiddenLookup[$toolName])) {
                $timeoutSeconds[$toolName] = $seconds;
            }
        }

        return new ActiveToolSet(
            toolNames: $toolNames,
            allowListNames: $allowList,
            executionModes: $executionModes,
            timeoutSeconds: $timeoutSeconds,
        );
    }

    private function resolveCatalogRunId(string $runId): string
    {
        try {
            $parentRunId = $this->relationshipReader->readParentRunId($runId);
        } catch (\RuntimeException) {
            return $runId;
        }

        return $parentRunId ?? $runId;
    }
}
