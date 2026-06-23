<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;

/**
 * Resolves tool and MCP policy for a child agent run.
 *
 * The resolved policy is derived from the agent definition plus
 * hard safety rules:
 *   - The 'subagent' tool is always excluded from child tool lists
 *     to prevent recursive agent launches by default.
 *   - MCP policy mode none/specific/all is read from the definition.
 *
 * The resolved policy should be stored in RunMetadata::toolsScope so
 * downstream resolver/filtering can enforce it per-run without
 * mutating the global ToolRegistry.
 */
final readonly class AgentToolPolicyResolver
{
    /**
     * Resolve the effective tool and MCP policy for a child run.
     *
     * @return array{tools: list<string>, mcp: array{mode: string, tools: list<string>}}
     */
    public function resolve(AgentDefinitionDTO $definition, bool $allowSubagent = false): array
    {
        $tools = $definition->tools;

        // Hard safety: exclude 'subagent' from child tool lists
        // unless explicitly allowed.  This prevents recursion by default.
        if (!$allowSubagent) {
            $tools = array_values(array_filter(
                $tools,
                static fn (string $name): bool => 'subagent' !== $name,
            ));
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
