<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;

/**
 * Resolves tool and MCP policy for a child agent run from frontmatter `tools` and `mcp:` selectors.
 */
final readonly class AgentToolPolicyResolver
{
    public function __construct(
        private ToolRegistryInterface $toolRegistry,
        private AgentMcpToolsResolver $mcpToolsResolver,
        private AgentsConfig $agentsConfig,
    ) {
    }

    /**
     * @return array{tools: list<string>, mcp: array{mode: string, tools: list<string>}}
     */
    public function resolve(AgentDefinitionDTO $definition, string $catalogRunId): array
    {
        $mcpResolved = $this->mcpToolsResolver->resolve($definition->tools, $catalogRunId);

        if (null === $definition->tools) {
            // activeToolNames() already includes every dynamically registered MCP tool
            // (availability=all and availability=specific). Strip the complete catalog MCP
            // runtime-name set first, then re-add only resolver-selected MCP tools (global by
            // default for omitted tools).
            $catalogMcp = array_fill_keys($mcpResolved['catalog_mcp_runtime_tools'], true);
            $tools = array_values(array_filter(
                $this->toolRegistry->activeToolNames(),
                static fn (string $name): bool => !isset($catalogMcp[$name]),
            ));
        } else {
            $tools = $mcpResolved['non_mcp_tools'];
        }

        foreach ($mcpResolved['mcp_runtime_tools'] as $mcpTool) {
            if (!\in_array($mcpTool, $tools, true)) {
                $tools[] = $mcpTool;
            }
        }

        // Nested child launches are forbidden for every child run: always omit recursion tools
        // after MCP merge so neither inherit-all nor explicit lists can reintroduce them.
        $tools = array_values(array_filter(
            $tools,
            static fn (string $name): bool => 'subagent' !== $name && 'fork' !== $name,
        ));

        // Parent-only tools (settings, hatfield_docs, …) are stripped for every child,
        // whether the definition omitted tools (inherit-all) or requested them explicitly.
        $excluded = $this->agentsConfig->subagentExcludedTools;
        if ([] !== $excluded) {
            $tools = array_values(array_filter(
                $tools,
                static fn (string $name): bool => !\in_array($name, $excluded, true),
            ));
        }

        return [
            'tools' => $tools,
            'mcp' => $mcpResolved['mcp_policy'],
        ];
    }
}
