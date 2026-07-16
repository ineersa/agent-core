<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;

/**
 * Resolves tool and MCP policy for a child agent run from frontmatter `tools` and `mcp:` selectors.
 */
final readonly class AgentToolPolicyResolver
{
    public function __construct(
        private ToolRegistryInterface $toolRegistry,
        private AgentMcpToolsResolver $mcpToolsResolver,
    ) {
    }

    /**
     * @return array{tools: list<string>, mcp: array{mode: string, tools: list<string>}}
     */
    public function resolve(AgentDefinitionDTO $definition, string $catalogRunId, bool $allowSubagent = false): array
    {
        $mcpResolved = $this->mcpToolsResolver->resolve($definition->tools, $catalogRunId);

        if (null === $definition->tools) {
            $tools = $this->toolRegistry->activeToolNames();
        } else {
            $tools = $mcpResolved['non_mcp_tools'];
        }

        if (!$allowSubagent) {
            // Child runs cannot launch fork or subagent; omit both from the advertised toolset.
            $tools = array_values(array_filter(
                $tools,
                static fn (string $name): bool => 'subagent' !== $name && 'fork' !== $name,
            ));
        }

        foreach ($mcpResolved['mcp_runtime_tools'] as $mcpTool) {
            if (!\in_array($mcpTool, $tools, true)) {
                $tools[] = $mcpTool;
            }
        }

        return [
            'tools' => $tools,
            'mcp' => $mcpResolved['mcp_policy'],
        ];
    }
}
