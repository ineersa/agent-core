<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;

final class SubagentLaunchDefinitionPolicyService
{
    public function __construct(
        private readonly AgentDefinitionCatalog $catalog,
        private readonly AgentDepthGuard $depthGuard,
        private readonly AgentToolPolicyResolver $policyResolver,
        private readonly SubagentRunMetadataReader $metadataReader,
    ) {
    }

    public function assertDepthAllowed(string $parentRunId): void
    {
        $parentIsAgentChild = $this->metadataReader->isAgentChild($parentRunId);
        $blockReason = $this->depthGuard->checkLaunchAllowed($parentIsAgentChild);
        if (null !== $blockReason) {
            throw new ToolCallException($blockReason, retryable: false);
        }
    }

    public function requireParallelDefinition(string $agentName): AgentDefinitionDTO
    {
        $definition = $this->requireForegroundDefinition($agentName);
        if (!$definition->parallelAllowed) {
            throw new ToolCallException(\sprintf('Agent "%s" does not allow parallel execution. Set parallelAllowed: true in the agent definition or use single subagent mode.', $agentName), retryable: false);
        }

        return $definition;
    }

    public function requireForkDefinition(): AgentDefinitionDTO
    {
        $definition = $this->catalog->get('fork');
        if (null !== $definition) {
            return $definition;
        }

        return new AgentDefinitionDTO(
            name: 'fork',
            description: 'Internal fork child',
            tools: null,
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::All),
            instructions: '',
            inheritProjectContext: true,
            inheritAgentsMd: true,
            foregroundAllowed: true,
            parallelAllowed: false,
            disabled: true,
        );
    }

    public function requireForegroundDefinition(string $agentName): AgentDefinitionDTO
    {
        try {
            $definition = $this->catalog->requireEnabled($agentName);
        } catch (\RuntimeException $e) {
            throw new ToolCallException(\sprintf('Agent "%s" is not available: %s', $agentName, $e->getMessage()), retryable: false);
        }

        if (!$definition->foregroundAllowed) {
            throw new ToolCallException(\sprintf('Agent "%s" does not allow foreground execution.', $agentName), retryable: false);
        }

        return $definition;
    }

    /**
     * @return array{tools:list<string>,mcp:array<string,mixed>}
     */
    public function resolveToolPolicy(AgentDefinitionDTO $definition, string $parentRunId, bool $allowSubagentLaunch): array
    {
        return $this->policyResolver->resolve($definition, $parentRunId, $allowSubagentLaunch);
    }
}
