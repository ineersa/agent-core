<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

use Ineersa\AgentCore\Domain\Tool\ToolLaunchContextDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentSingleChildLaunchProfileDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;
use Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder;

/**
 * Builds one-child launch plans and prepares their pending native work.
 */
final class DeferredSubagentBatchPreparationService
{
    public function __construct(
        private readonly SubagentLaunchPreparationService $launchPreparation,
        private readonly DeferredSubagentBatchIdentityFactory $identityFactory,
        private readonly ChildRunArtifactLifecycleService $artifactLifecycle,
        private readonly SubagentChildLaunchInputFactory $subagentLaunchInputFactory,
        private readonly ForkChildLaunchInputBuilder $forkLaunchInputBuilder,
    ) {
    }

    public function buildLaunchPlan(
        string $parentRunId,
        string $toolCallId,
        string $agentName,
        string $task,
        ?string $parentModel = null,
        ?ToolLaunchContextDTO $launchContext = null,
    ): DeferredSubagentBatchLaunchPlanDTO {
        $this->launchPreparation->assertDepthAllowed($parentRunId);

        $lifecycleId = $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $taskText = trim($task);
        $agentName = trim($agentName);
        $definition = $this->launchPreparation->requireForegroundDefinition($agentName);
        $ids = $this->identityFactory->childIdentity($parentRunId, $toolCallId, 1);
        $launch = $this->subagentLaunchInputFactory->resolveLaunchIdentity(
            $definition,
            $parentRunId,
            $parentModel,
        );
        $childIntent = new DeferredSubagentBatchChildIntentDTO(
            batchIndex: 1,
            childRunId: $ids['childRunId'],
            artifactId: $ids['artifactId'],
            agentName: $agentName,
            task: $taskText,
            launchModel: $launch['model'],
            launchReasoning: $launch['reasoning'],
        );
        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $ids['childRunId'],
            artifactId: $ids['artifactId'],
            displayName: $agentName,
            taskSummary: $taskText,
            artifactKind: AgentArtifactKindEnum::Subagent,
            batchIndex: 1,
        );

        return new DeferredSubagentBatchLaunchPlanDTO(
            lifecycleId: $lifecycleId,
            childIntent: $childIntent,
            definition: $definition,
            identity: $identity,
            parentModel: $parentModel,
            launchContext: $launchContext,
        );
    }

    /**
     * Explicit one-child fork plan from a required profile (no catalog agent-name resolution).
     */
    public function buildSingleChildProfiledLaunchPlan(
        string $parentRunId,
        string $toolCallId,
        string $task,
        DeferredSubagentSingleChildLaunchProfileDTO $profile,
        ?string $parentModel = null,
        ?ToolLaunchContextDTO $launchContext = null,
    ): DeferredSubagentBatchLaunchPlanDTO {
        $this->launchPreparation->assertDepthAllowed($parentRunId);

        $lifecycleId = $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $taskText = trim($task);
        $ids = $this->identityFactory->childIdentity($parentRunId, $toolCallId, 1);
        $launch = $this->forkLaunchInputBuilder->resolveLaunchIdentity(
            $parentRunId,
            $profile->definition->model,
            $profile->reasoningOverride,
            $parentModel,
        );
        $childIntent = new DeferredSubagentBatchChildIntentDTO(
            batchIndex: 1,
            childRunId: $ids['childRunId'],
            artifactId: $ids['artifactId'],
            agentName: $profile->displayAgentName,
            task: $taskText,
            launchModel: $launch['model'],
            launchReasoning: $launch['reasoning'],
        );
        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $ids['childRunId'],
            artifactId: $ids['artifactId'],
            displayName: $profile->displayAgentName,
            taskSummary: $taskText,
            artifactKind: $profile->artifactKind,
            batchIndex: 1,
        );

        return new DeferredSubagentBatchLaunchPlanDTO(
            lifecycleId: $lifecycleId,
            childIntent: $childIntent,
            definition: $profile->definition,
            identity: $identity,
            parentModel: $parentModel,
            launchContext: $launchContext,
        );
    }

    /**
     * @throws DeferredSubagentBatchPreparationFailure
     */
    public function preparePendingChild(
        string $parentRunId,
        DeferredSubagentBatchProjectionDTO $projection,
        DeferredSubagentBatchLaunchPlanDTO $plan,
    ): ?PreparedAgentChildRunDTO {
        $intent = $plan->childIntent;
        $childProjection = $this->childProjectionForIndex($projection->children, $intent->batchIndex);
        if (null !== $childProjection && DeferredSubagentChildLaunchStatusEnum::Launched === $childProjection->launchStatus) {
            return null;
        }

        if (null !== $childProjection && DeferredSubagentChildLaunchStatusEnum::Failed === $childProjection->launchStatus) {
            return null;
        }

        $artifactStatus = $this->artifactLifecycle->getArtifactStatus($parentRunId, $intent->artifactId);
        if ($this->isArtifactBeyondPending($artifactStatus)) {
            return null;
        }

        $identity = $plan->identity;
        $definition = $plan->definition ?? throw new \LogicException('Subagent launch requires a definition.');
        try {
            $this->launchPreparation->reserveIdentity($identity);
            $prepared = $this->launchPreparation->prepareFromDefinition(
                $parentRunId,
                $definition,
                $intent->agentName,
                $intent->task,
                $identity->artifactId,
                $identity->childRunId,
                skipReservation: true,
                identityTemplate: $identity,
                parentModel: $plan->parentModel,
                launchContext: $plan->launchContext,
            );
            $this->artifactLifecycle->ensureReservedPending($identity);

            return $prepared;
        } catch (\Throwable $e) {
            throw new DeferredSubagentBatchPreparationFailure($intent->batchIndex, $e);
        }
    }

    /**
     * Prepare the single pending child for an explicit profiled fork launch.
     *
     * @throws DeferredSubagentBatchPreparationFailure
     */
    public function preparePendingProfiledChild(
        string $parentRunId,
        DeferredSubagentBatchProjectionDTO $projection,
        DeferredSubagentBatchLaunchPlanDTO $plan,
        DeferredSubagentSingleChildLaunchProfileDTO $profile,
    ): ?PreparedAgentChildRunDTO {
        $intent = $plan->childIntent;
        $childProjection = $this->childProjectionForIndex($projection->children, $intent->batchIndex);
        if (null !== $childProjection && DeferredSubagentChildLaunchStatusEnum::Launched === $childProjection->launchStatus) {
            return null;
        }

        if (null !== $childProjection && DeferredSubagentChildLaunchStatusEnum::Failed === $childProjection->launchStatus) {
            return null;
        }

        $artifactStatus = $this->artifactLifecycle->getArtifactStatus($parentRunId, $intent->artifactId);
        if ($this->isArtifactBeyondPending($artifactStatus)) {
            return null;
        }

        $identity = $plan->identity;
        try {
            $this->launchPreparation->reserveIdentity($identity);
            $prepared = $this->launchPreparation->prepareForkFromProfile(
                $parentRunId,
                $profile,
                $intent->task,
                $identity->artifactId,
                $identity->childRunId,
                skipReservation: true,
                identityTemplate: $identity,
                parentModel: $plan->parentModel,
            );
            $this->artifactLifecycle->ensureReservedPending($identity);

            return $prepared;
        } catch (\Throwable $e) {
            throw new DeferredSubagentBatchPreparationFailure($intent->batchIndex, $e);
        }
    }

    public function isLaunched(
        string $parentRunId,
        DeferredSubagentBatchProjectionDTO $projection,
        DeferredSubagentBatchLaunchPlanDTO $plan,
        ?PreparedAgentChildRunDTO $prepared,
    ): bool {
        $child = $this->childProjectionForIndex($projection->children, $plan->childIntent->batchIndex);
        if (null !== $child && DeferredSubagentChildLaunchStatusEnum::Launched === $child->launchStatus) {
            return true;
        }

        return null !== $prepared || $this->isArtifactBeyondPending(
            $this->artifactLifecycle->getArtifactStatus($parentRunId, $plan->childIntent->artifactId),
        );
    }

    /**
     * @param list<DeferredSubagentChildProjectionDTO> $children
     */
    private function childProjectionForIndex(array $children, int $batchIndex): ?DeferredSubagentChildProjectionDTO
    {
        foreach ($children as $child) {
            if ($child->batchIndex === $batchIndex) {
                return $child;
            }
        }

        return null;
    }

    private function isArtifactBeyondPending(?AgentArtifactStatusEnum $status): bool
    {
        if (null === $status) {
            return false;
        }

        return AgentArtifactStatusEnum::Pending !== $status;
    }
}
