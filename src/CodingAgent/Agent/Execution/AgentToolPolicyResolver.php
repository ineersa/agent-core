<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;

/**
 * Resolves tool and MCP policy for a child agent run.
 *
 * The resolved policy is derived from the agent definition plus
 * hard safety rules:
 *   - The 'subagent' tool is always excluded from child tool lists
 *     to prevent recursive agent launches by default.
 *   - MCP policy modes:
 *     * none  — no MCP tools exposed.
 *     * all   — MCP tools are passed through; subagent is still
 *              excluded from the allowed_tools list.
 *     * specific — only the MCP tools named in the definition's MCP
 *                  policy are added to the allowed tool list.
 *
 * The resolved policy should be stored in RunMetadata::toolsScope so
 * downstream resolver/filtering can enforce it per-run without
 * mutating the global ToolRegistry.
 */
final readonly class AgentToolPolicyResolver
{
    public function __construct(
        private ToolRegistryInterface $toolRegistry,
    ) {
    }

    /**
     * Resolve the effective tool and MCP policy for a child run.
     *
     * @return array{tools: list<string>, mcp: array{mode: string, tools: list<string>}}
     */
    public function resolve(AgentDefinitionDTO $definition, bool $allowSubagent = false): array
    {
        $tools = null === $definition->tools
            ? $this->toolRegistry->activeToolNames()
            : $definition->tools;

        // Hard safety: exclude 'subagent' from child tool lists
        // unless explicitly allowed.  This prevents recursion by default.
        if (!$allowSubagent) {
            $tools = array_values(array_filter(
                $tools,
                static fn (string $name): bool => 'subagent' !== $name,
            ));
        }

        // MCP specific mode: merge MCP tool names into the allowed
        // tool list so downstream filtering can enforce them.
        if (McpAgentModeEnum::Specific === $definition->mcp->mode) {
            foreach ($definition->mcp->tools as $mcpTool) {
                if (!\in_array($mcpTool, $tools, true)) {
                    $tools[] = $mcpTool;
                }
            }
        }

        $mcp = [
            'mode' => $definition->mcp->mode->value,
            'tools' => $definition->mcp->tools,
        ];

        return [
            'tools' => $tools,
            'mcp' => $mcp,
        ];
    }
}
