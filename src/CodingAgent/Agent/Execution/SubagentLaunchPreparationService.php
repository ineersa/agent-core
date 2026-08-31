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

    public function prepareFromDefinition(
        string $parentRunId,
        AgentDefinitionDTO $definition,
        string $agentName,
        string $task,
        ?string $artifactId = null,
        ?string $childRunId = null,
        bool $skipReservation = false,
        ?ChildRunIdentityDTO $identityTemplate = null,
        ?string $parentModel = null,
    ): PreparedAgentChildRunDTO {
        $artifactId ??= 'agent_'.bin2hex(random_bytes(8));
        $childRunId ??= Uuid::v4()->toRfc4122();

        // Resolve concrete identity once before DTO construction (or reuse deferred plan identity).
        $identity = $identityTemplate ?? $this->newSubagentIdentity(
            $parentRunId,
            $childRunId,
            $artifactId,
            $agentName,
            $task,
            $definition,
            $parentModel,
        );

        $policy = $this->definitionPolicy->resolveToolPolicy($definition, $parentRunId);

        $prepared = $this->launchInputFactory->buildPrepared(
            $identity,
            $definition,
            $policy['tools'],
            $policy['mcp'],
            parentModel: $parentModel,
        );

        if (!$skipReservation) {
            $this->artifactLifecycle->reservePending($prepared->identity);
        }

        return $prepared;
    }

    /**
     * Explicit fork child preparation from a required profile (no catalog/name fallback).
     */
    public function prepareForkFromProfile(
        string $parentRunId,
        DeferredSubagentSingleChildLaunchProfileDTO $profile,
        string $task,
        ?string $artifactId = null,
        ?string $childRunId = null,
        bool $skipReservation = false,
        ?ChildRunIdentityDTO $identityTemplate = null,
        ?string $parentModel = null,
    ): PreparedAgentChildRunDTO {
        if (AgentArtifactKindEnum::Fork !== $profile->artifactKind) {
            throw new \InvalidArgumentException('prepareForkFromProfile requires artifact kind Fork.');
        }

        $artifactId ??= 'agent_'.bin2hex(random_bytes(8));
        $childRunId ??= Uuid::v4()->toRfc4122();

        $identity = $identityTemplate ?? $this->newForkIdentity(
            $parentRunId,
            $childRunId,
            $artifactId,
            $profile,
            $task,
            $parentModel,
        );

        $prepared = $this->forkLaunchInputBuilder->buildPrepared(
            $identity,
            new ForkLaunchTaskDTO(
                task: $task,
                inheritedMessages: $profile->inheritedMessages,
                modelOverride: $profile->definition->model,
                reasoningOverride: $profile->reasoningOverride,
            ),
            $this->forkToolPolicyResolver->resolve($parentRunId),
            parentModel: $parentModel,
        );

        if (!$skipReservation) {
            $this->artifactLifecycle->reservePending($prepared->identity);
        }

        return $prepared;
    }

    private function newSubagentIdentity(
        string $parentRunId,
        string $childRunId,
        string $artifactId,
        string $agentName,
        string $task,
        AgentDefinitionDTO $definition,
        ?string $parentModel,
    ): ChildRunIdentityDTO {
        $launch = $this->launchInputFactory->resolveLaunchIdentity($definition, $parentRunId, $parentModel);

        return new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $childRunId,
            artifactId: $artifactId,
            displayName: $agentName,
            taskSummary: $task,
            launchModel: $launch['model'],
            launchReasoning: $launch['reasoning'],
            artifactKind: AgentArtifactKindEnum::Subagent,
        );
    }

    private function newForkIdentity(
        string $parentRunId,
        string $childRunId,
        string $artifactId,
        DeferredSubagentSingleChildLaunchProfileDTO $profile,
        string $task,
        ?string $parentModel,
    ): ChildRunIdentityDTO {
        $launch = $this->forkLaunchInputBuilder->resolveLaunchIdentity(
            $parentRunId,
            $profile->definition->model,
            $profile->reasoningOverride,
            $parentModel,
        );

        return new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $childRunId,
            artifactId: $artifactId,
            displayName: $profile->displayAgentName,
            taskSummary: $task,
            launchModel: $launch['model'],
            launchReasoning: $launch['reasoning'],
            artifactKind: $profile->artifactKind,
        );
    }
}
