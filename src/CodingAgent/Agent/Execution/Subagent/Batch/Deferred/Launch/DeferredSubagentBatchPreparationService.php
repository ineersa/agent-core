<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentSingleChildLaunchProfileDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;
use Ineersa\CodingAgent\Agent\Execution\SubagentTaskDTO;

/**
 * Builds the deferred batch launch plan and prepares still-pending children (Piece 4A).
 */
final class DeferredSubagentBatchPreparationService
{
    public function __construct(
        private readonly SubagentLaunchPreparationService $launchPreparation,
        private readonly DeferredSubagentBatchIdentityFactory $identityFactory,
        private readonly ChildRunArtifactLifecycleService $artifactLifecycle,
    ) {
    }

    /**
     * @param list<SubagentTaskDTO> $tasks
     */
    public function buildLaunchPlan(
        string $parentRunId,
        string $toolCallId,
        array $tasks,
        ChildRunBatchExecutionModeEnum $executionMode,
    ): DeferredSubagentBatchLaunchPlanDTO {
        $this->launchPreparation->assertDepthAllowed($parentRunId);

        $lifecycleId = $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $childIntents = [];
        $definitionsByBatchIndex = [];
        $identities = [];

        foreach ($tasks as $index => $taskDto) {
            $batchIndex = $index + 1;
            $taskText = $taskDto->trimmedTask();
            $agentName = $taskDto->trimmedAgent();
            $definition = $this->resolveDefinition($agentName, $executionMode);
            $definitionsByBatchIndex[$batchIndex] = $definition;
            $ids = $this->identityFactory->childIdentity($parentRunId, $toolCallId, $batchIndex);
            $childIntents[] = new DeferredSubagentBatchChildIntentDTO(
                batchIndex: $batchIndex,
                childRunId: $ids['childRunId'],
                artifactId: $ids['artifactId'],
                agentName: $agentName,
                task: $taskText,
                definitionModel: $definition->model,
            );
            $identities[] = new ChildRunIdentityDTO(
                parentRunId: $parentRunId,
                childRunId: $ids['childRunId'],
                artifactId: $ids['artifactId'],
                displayName: $agentName,
                taskSummary: $taskText,
                definitionModel: $definition->model,
                artifactKind: AgentArtifactKindEnum::Subagent,
                batchIndex: $batchIndex,
            );
        }

        return new DeferredSubagentBatchLaunchPlanDTO(
            lifecycleId: $lifecycleId,
            executionMode: $executionMode,
            totalChildCount: \count($tasks),
            childIntents: $childIntents,
            definitionsByBatchIndex: $definitionsByBatchIndex,
            identities: $identities,
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
    ): DeferredSubagentBatchLaunchPlanDTO {
        $this->launchPreparation->assertDepthAllowed($parentRunId);

        $lifecycleId = $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $taskText = trim($task);
        $ids = $this->identityFactory->childIdentity($parentRunId, $toolCallId, 1);
        $childIntents = [
            new DeferredSubagentBatchChildIntentDTO(
                batchIndex: 1,
                childRunId: $ids['childRunId'],
                artifactId: $ids['artifactId'],
                agentName: $profile->displayAgentName,
                task: $taskText,
                definitionModel: $profile->definition->model,
            ),
        ];
        $identities = [
            new ChildRunIdentityDTO(
                parentRunId: $parentRunId,
                childRunId: $ids['childRunId'],
                artifactId: $ids['artifactId'],
                displayName: $profile->displayAgentName,
                taskSummary: $taskText,
                definitionModel: $profile->definition->model,
                artifactKind: $profile->artifactKind,
                batchIndex: 1,
            ),
        ];

        return new DeferredSubagentBatchLaunchPlanDTO(
            lifecycleId: $lifecycleId,
            executionMode: ChildRunBatchExecutionModeEnum::Single,
            totalChildCount: 1,
            childIntents: $childIntents,
            definitionsByBatchIndex: [1 => $profile->definition],
            identities: $identities,
        );
    }

    /**
     * @return list<PreparedAgentChildRunDTO>
     *
     * @throws DeferredSubagentBatchPreparationFailure
     */
    public function preparePendingChildren(
        string $parentRunId,
        DeferredSubagentBatchProjectionDTO $projection,
        DeferredSubagentBatchLaunchPlanDTO $plan,
    ): array {
        $preparedChildren = [];

        foreach ($plan->childIntents as $intent) {
            $childProjection = $this->childProjectionForIndex($projection->children, $intent->batchIndex);
            if (null !== $childProjection && DeferredSubagentChildLaunchStatusEnum::Launched === $childProjection->launchStatus) {
                continue;
            }

            if (null !== $childProjection && DeferredSubagentChildLaunchStatusEnum::Failed === $childProjection->launchStatus) {
                continue;
            }

            $artifactStatus = $this->artifactLifecycle->getArtifactStatus($parentRunId, $intent->artifactId);
            if ($this->isArtifactBeyondPending($artifactStatus)) {
                continue;
            }

            $identity = $this->identityForIndex($plan->identities, $intent->batchIndex);
            $definition = $plan->definitionsByBatchIndex[$intent->batchIndex];
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
                );
                $this->artifactLifecycle->ensureReservedPending($identity);
                $preparedChildren[] = $prepared;
            } catch (\Throwable $e) {
                throw new DeferredSubagentBatchPreparationFailure($intent->batchIndex, $e);
            }
        }

        return $preparedChildren;
    }

    /**
     * Prepare the single pending child for an explicit profiled fork launch.
     *
     * @return list<PreparedAgentChildRunDTO>
     *
     * @throws DeferredSubagentBatchPreparationFailure
     */
    public function preparePendingProfiledChild(
        string $parentRunId,
        DeferredSubagentBatchProjectionDTO $projection,
        DeferredSubagentBatchLaunchPlanDTO $plan,
        DeferredSubagentSingleChildLaunchProfileDTO $profile,
    ): array {
        if (1 !== $plan->totalChildCount || 1 !== \count($plan->childIntents) || 1 !== \count($plan->identities)) {
            throw new \InvalidArgumentException('Profiled deferred launch preparation requires exactly one child intent.');
        }

        $intent = $plan->childIntents[0];
        $childProjection = $this->childProjectionForIndex($projection->children, $intent->batchIndex);
        if (null !== $childProjection && DeferredSubagentChildLaunchStatusEnum::Launched === $childProjection->launchStatus) {
            return [];
        }

        if (null !== $childProjection && DeferredSubagentChildLaunchStatusEnum::Failed === $childProjection->launchStatus) {
            return [];
        }

        $artifactStatus = $this->artifactLifecycle->getArtifactStatus($parentRunId, $intent->artifactId);
        if ($this->isArtifactBeyondPending($artifactStatus)) {
            return [];
        }

        $identity = $this->identityForIndex($plan->identities, $intent->batchIndex);
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
            );
            $this->artifactLifecycle->ensureReservedPending($identity);

            return [$prepared];
        } catch (\Throwable $e) {
            throw new DeferredSubagentBatchPreparationFailure($intent->batchIndex, $e);
        }
    }

    /**
     * @param list<PreparedAgentChildRunDTO> $preparedChildren
     *
     * @return list<int>
     */
    public function collectLaunchedBatchIndices(
        string $parentRunId,
        DeferredSubagentBatchProjectionDTO $projection,
        DeferredSubagentBatchLaunchPlanDTO $plan,
        array $preparedChildren,
    ): array {
        $preparedIndexes = [];
        foreach ($preparedChildren as $prepared) {
            $preparedIndexes[$prepared->identity->batchIndex] = true;
        }

        $launched = [];
        foreach ($plan->childIntents as $intent) {
            $childProjection = $this->childProjectionForIndex($projection->children, $intent->batchIndex);
            if (null !== $childProjection && DeferredSubagentChildLaunchStatusEnum::Launched === $childProjection->launchStatus) {
                $launched[] = $intent->batchIndex;

                continue;
            }

            if (isset($preparedIndexes[$intent->batchIndex])) {
                $launched[] = $intent->batchIndex;

                continue;
            }

            $artifactStatus = $this->artifactLifecycle->getArtifactStatus($parentRunId, $intent->artifactId);
            if ($this->isArtifactBeyondPending($artifactStatus)) {
                $launched[] = $intent->batchIndex;
            }
        }

        return $launched;
    }

    private function resolveDefinition(string $agentName, ChildRunBatchExecutionModeEnum $executionMode): AgentDefinitionDTO
    {
        return ChildRunBatchExecutionModeEnum::Single === $executionMode
            ? $this->launchPreparation->requireForegroundDefinition($agentName)
            : $this->launchPreparation->requireParallelDefinition($agentName);
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

    /**
     * @param list<ChildRunIdentityDTO> $identities
     */
    private function identityForIndex(array $identities, int $batchIndex): ChildRunIdentityDTO
    {
        foreach ($identities as $identity) {
            if ($identity->batchIndex === $batchIndex) {
                return $identity;
            }
        }

        throw new \LogicException('Deferred subagent batch launch identity missing for prepared child.');
    }

    private function isArtifactBeyondPending(?AgentArtifactStatusEnum $status): bool
    {
        if (null === $status) {
            return false;
        }

        return AgentArtifactStatusEnum::Pending !== $status;
    }
}
