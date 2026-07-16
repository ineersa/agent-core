<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentLaunchDefinitionPolicyService;
use Symfony\Component\Uid\Uuid;

/**
 * Subagent application orchestrator: policy, reservation ordering, and typed launch construction.
 */
final class SubagentLaunchPreparationService
{
    public function __construct(
        private readonly SubagentLaunchDefinitionPolicyService $definitionPolicy,
        private readonly ChildRunArtifactLifecycleService $artifactLifecycle,
        private readonly SubagentChildLaunchInputFactory $launchInputFactory,
    ) {
    }

    public function reserveIdentity(ChildRunIdentityDTO $identity): void
    {
        $this->artifactLifecycle->reservePending($identity);
    }

    public function assertDepthAllowed(string $parentRunId): void
    {
        $this->definitionPolicy->assertDepthAllowed($parentRunId);
    }

    public function requireParallelDefinition(string $agentName): AgentDefinitionDTO
    {
        return $this->definitionPolicy->requireParallelDefinition($agentName);
    }

    public function requireForkDefinition(): AgentDefinitionDTO
    {
        return $this->definitionPolicy->requireForkDefinition();
    }

    public function requireForegroundDefinition(string $agentName): AgentDefinitionDTO
    {
        return $this->definitionPolicy->requireForegroundDefinition($agentName);
    }

    public function prepareSingle(
        string $parentRunId,
        string $agentName,
        string $task,
    ): PreparedAgentChildRunDTO {
        $definition = $this->definitionPolicy->requireForegroundDefinition($agentName);
        $this->definitionPolicy->assertDepthAllowed($parentRunId);

        return $this->prepareFromDefinition($parentRunId, $definition, $agentName, $task);
    }

    public function prepareFromDefinition(
        string $parentRunId,
        AgentDefinitionDTO $definition,
        string $agentName,
        string $task,
        ?string $artifactId = null,
        ?string $childRunId = null,
        bool $skipReservation = false,
        ?ChildRunIdentityDTO $identityTemplate = null,
    ): PreparedAgentChildRunDTO {
        $artifactId ??= 'agent_'.bin2hex(random_bytes(8));
        $childRunId ??= Uuid::v4()->toRfc4122();

        $allowSubagentLaunch = null !== $definition->tools && \in_array('subagent', $definition->tools, true);
        $policy = $this->definitionPolicy->resolveToolPolicy($definition, $parentRunId, $allowSubagentLaunch);

        $identity = $identityTemplate ?? new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $childRunId,
            artifactId: $artifactId,
            displayName: $agentName,
            taskSummary: $task,
            definitionModel: $definition->model,
            artifactKind: AgentArtifactKindEnum::Subagent,
        );

        if (!$skipReservation) {
            $this->artifactLifecycle->reservePending($identity);
        }

        return $this->launchInputFactory->buildPrepared(
            $identity,
            $definition,
            $policy['tools'],
            $policy['mcp'],
        );
    }
}
