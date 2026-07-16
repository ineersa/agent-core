<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Launch;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\AgentChildLaunchTaskInterface;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\DeferredAgentChildBatchChildIntentDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\DeferredAgentChildBatchLaunchPlanDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\DeferredAgentChildBatchPreparationFailure;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\DeferredAgentChildBatchPreparationInterface;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\Fork\ChildRun\Preparation\ForkChildLaunchInputFactory;
use Ineersa\CodingAgent\Agent\Execution\Fork\ForkLaunchTaskDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;

final class DeferredForkBatchPreparationService implements DeferredAgentChildBatchPreparationInterface
{
    public function __construct(
        private readonly DeferredForkBatchIdentityFactory $identityFactory,
        private readonly ChildRunArtifactLifecycleService $artifactLifecycle,
        private readonly ForkChildLaunchInputFactory $launchInputFactory,
    ) {
    }

    /**
     * @param list<AgentChildLaunchTaskInterface> $tasks
     */
    public function buildLaunchPlan(
        string $parentRunId,
        string $toolCallId,
        array $tasks,
        ChildRunBatchExecutionModeEnum $executionMode,
    ): DeferredAgentChildBatchLaunchPlanDTO {
        if (ChildRunBatchExecutionModeEnum::Single !== $executionMode || 1 !== \count($tasks)) {
            throw new \LogicException('Fork deferred batch supports exactly one single child task.');
        }

        $task = $tasks[0];

        $lifecycleId = $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $ids = $this->identityFactory->childIdentity($parentRunId, $toolCallId, 1);
        $taskText = $task->taskSummary();
        $childIntents = [
            new DeferredAgentChildBatchChildIntentDTO(
                batchIndex: 1,
                childRunId: $ids['childRunId'],
                artifactId: $ids['artifactId'],
                agentName: 'fork',
                task: $taskText,
                definitionModel: $task->definitionModel(),
                artifactKind: AgentArtifactKindEnum::Fork,
                reasoningOverride: $task->reasoningOverride(),
            ),
        ];
        $identities = [
            new ChildRunIdentityDTO(
                parentRunId: $parentRunId,
                childRunId: $ids['childRunId'],
                artifactId: $ids['artifactId'],
                displayName: 'fork',
                taskSummary: $taskText,
                definitionModel: $task->definitionModel(),
                artifactKind: AgentArtifactKindEnum::Fork,
                batchIndex: 1,
            ),
        ];

        return new DeferredAgentChildBatchLaunchPlanDTO(
            lifecycleId: $lifecycleId,
            executionMode: $executionMode,
            totalChildCount: 1,
            childIntents: $childIntents,
            identities: $identities,
        );
    }

    /**
     * @return list<PreparedAgentChildRunDTO>
     *
     * @throws DeferredAgentChildBatchPreparationFailure
     */
    public function preparePendingChildren(
        string $parentRunId,
        DeferredSubagentBatchProjectionDTO $projection,
        DeferredAgentChildBatchLaunchPlanDTO $plan,
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
            $forkTask = new ForkLaunchTaskDTO(
                task: $intent->task,
                modelOverride: $intent->definitionModel,
                reasoningOverride: $intent->reasoningOverride,
            );
            try {
                $this->artifactLifecycle->reservePending($identity);
                $prepared = $this->launchInputFactory->buildPrepared($identity, $forkTask);
                $this->artifactLifecycle->ensureReservedPending($identity);
                $preparedChildren[] = $prepared;
            } catch (\Throwable $e) {
                throw new DeferredAgentChildBatchPreparationFailure($intent->batchIndex, $e);
            }
        }

        return $preparedChildren;
    }

    /**
     * @param list<PreparedAgentChildRunDTO> $preparedChildren
     *
     * @return list<int>
     */
    public function collectLaunchedBatchIndices(
        string $parentRunId,
        DeferredSubagentBatchProjectionDTO $projection,
        DeferredAgentChildBatchLaunchPlanDTO $plan,
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

        throw new \LogicException('Deferred fork batch launch identity missing for prepared child.');
    }

    private function isArtifactBeyondPending(?AgentArtifactStatusEnum $status): bool
    {
        if (null === $status) {
            return false;
        }

        return AgentArtifactStatusEnum::Pending !== $status;
    }
}
