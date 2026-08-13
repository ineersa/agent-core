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
        ?string $parentModel = null,
    ): PreparedAgentChildRunDTO {
        $definition = $this->definitionPolicy->requireForegroundDefinition($agentName);
        $this->definitionPolicy->assertDepthAllowed($parentRunId);

        return $this->prepareFromDefinition($parentRunId, $definition, $agentName, $task, parentModel: $parentModel);
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

        // Seed identity carries ids/task only; buildPrepared rewrites with concrete launch model/reasoning.
        $identity = $identityTemplate ?? new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $childRunId,
            artifactId: $artifactId,
            displayName: $agentName,
            taskSummary: $task,
            launchModel: $this->seedLaunchModel($definition->model, $parentModel),
            launchReasoning: $this->seedLaunchReasoning($definition->thinking),
            artifactKind: AgentArtifactKindEnum::Subagent,
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

        // Seed identity carries ids/task only; buildPrepared rewrites with concrete launch model/reasoning.
        $identity = $identityTemplate ?? new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $childRunId,
            artifactId: $artifactId,
            displayName: $profile->displayAgentName,
            taskSummary: $task,
            launchModel: $this->seedLaunchModel($profile->definition->model, $parentModel),
            launchReasoning: $this->seedLaunchReasoning($profile->reasoningOverride ?? $profile->definition->thinking),
            artifactKind: $profile->artifactKind,
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

    private function seedLaunchModel(?string $definitionModel, ?string $parentModel): string
    {
        foreach ([$definitionModel, $parentModel] as $candidate) {
            if (\is_string($candidate) && '' !== trim($candidate)) {
                return trim($candidate);
            }
        }

        // Temporary seed so ChildRunIdentityDTO can exist before factory resolution.
        // buildPrepared always rewrites identity from canonical resolution.
        return 'pending-model';
    }

    private function seedLaunchReasoning(?string $thinking): string
    {
        if (\is_string($thinking) && '' !== trim($thinking)) {
            return trim($thinking);
        }

        // Temporary seed; buildPrepared rewrites from canonical resolution.
        return 'medium';
    }
}
