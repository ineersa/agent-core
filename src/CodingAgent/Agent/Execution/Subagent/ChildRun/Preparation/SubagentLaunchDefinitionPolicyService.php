<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface;

final class SubagentLaunchDefinitionPolicyService
{
    public function __construct(
        private readonly AgentDefinitionCatalog $catalog,
        private readonly AgentDepthGuard $depthGuard,
        private readonly AgentToolPolicyResolver $policyResolver,
        private readonly RunRelationshipReaderInterface $relationshipReader,
    ) {
    }

    public function assertDepthAllowed(string $parentRunId): void
    {
        try {
            $this->relationshipReader->requireKnownTopLevel($parentRunId);
        } catch (\RuntimeException $e) {
            throw new ToolCallException($e->getMessage(), retryable: false);
        }
        $blockReason = $this->depthGuard->checkLaunchAllowed();
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

    public function requireForegroundDefinition(string $agentName): AgentDefinitionDTO
    {
        try {
            return $this->catalog->require($agentName);
        } catch (\RuntimeException $e) {
            throw new ToolCallException(\sprintf('Agent "%s" is not available: %s', $agentName, $e->getMessage()), retryable: false);
        }
    }

    /**
     * @return array{tools:list<string>,mcp:array<string,mixed>}
     */
    public function resolveToolPolicy(AgentDefinitionDTO $definition, string $parentRunId): array
    {
        return $this->policyResolver->resolve($definition, $parentRunId);
    }
}
