<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentSingleChildLaunchProfileDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentLaunchDefinitionPolicyService;
use Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder;
use Ineersa\CodingAgent\Agent\Fork\ForkLaunchTaskDTO;
use Ineersa\CodingAgent\Agent\Fork\ForkToolPolicyResolver;
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
        private readonly ForkChildLaunchInputBuilder $forkLaunchInputBuilder,
        private readonly ForkToolPolicyResolver $forkToolPolicyResolver,
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
        ?DeferredSubagentSingleChildLaunchProfileDTO $singleChildProfile = null,
    ): PreparedAgentChildRunDTO {
        $artifactId ??= 'agent_'.bin2hex(random_bytes(8));
        $childRunId ??= Uuid::v4()->toRfc4122();

        $identity = $identityTemplate ?? new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $childRunId,
            artifactId: $artifactId,
            displayName: $agentName,
            taskSummary: $task,
            definitionModel: $definition->model,
            artifactKind: null !== $singleChildProfile ? $singleChildProfile->artifactKind : AgentArtifactKindEnum::Subagent,
        );

        if (!$skipReservation) {
            $this->artifactLifecycle->reservePending($identity);
        }

        // Typed single-child profile data (fork): already-compacted messages + overrides.
        // Branch is on artifact kind, not agent-name string checks.
        if (null !== $singleChildProfile && AgentArtifactKindEnum::Fork === $singleChildProfile->artifactKind) {
            return $this->forkLaunchInputBuilder->buildPrepared(
                $identity,
                new ForkLaunchTaskDTO(
                    task: $task,
                    modelOverride: $singleChildProfile->modelOverride,
                    reasoningOverride: $singleChildProfile->reasoningOverride,
                    inheritedMessages: $singleChildProfile->inheritedMessages,
                ),
                $this->forkToolPolicyResolver->resolve($parentRunId),
            );
        }

        $allowSubagentLaunch = null !== $definition->tools && \in_array('subagent', $definition->tools, true);
        $policy = $this->definitionPolicy->resolveToolPolicy($definition, $parentRunId, $allowSubagentLaunch);

        return $this->launchInputFactory->buildPrepared(
            $identity,
            $definition,
            $policy['tools'],
            $policy['mcp'],
        );
    }
}
