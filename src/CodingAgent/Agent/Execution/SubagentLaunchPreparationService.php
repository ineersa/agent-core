<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentArtifactReservationService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildLaunchInputFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentLaunchDefinitionPolicyService;
use Symfony\Component\Uid\Uuid;

/**
 * Subagent application orchestrator: policy, reservation ordering, and typed launch construction.
 */
final class SubagentLaunchPreparationService
{
    public function __construct(
        private readonly SubagentLaunchDefinitionPolicyService $definitionPolicy,
        private readonly SubagentArtifactReservationService $artifactReservation,
        private readonly SubagentChildLaunchInputFactory $launchInputFactory,
    ) {
    }

    public function reserveIdentity(ChildRunIdentityDTO $identity): void
    {
        $this->artifactReservation->reserve($identity);
    }

    public function assertDepthAllowed(string $parentRunId): void
    {
        $this->definitionPolicy->assertDepthAllowed($parentRunId);
    }

    public function requireParallelDefinition(string $agentName): AgentDefinitionDTO
    {
        return $this->definitionPolicy->requireParallelDefinition($agentName);
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
            $this->artifactReservation->reserve($identity);
        }

        return $this->launchInputFactory->buildPrepared(
            $identity,
            $definition,
            $policy['tools'],
            $policy['mcp'],
        );
    }
}
