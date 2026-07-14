<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;
use Ineersa\CodingAgent\Agent\Execution\SubagentTaskDTO;

/**
 * Builds ordered launch intents and prepares still-pending children for deferred batch launch (Piece 4A).
 */
final class DeferredSubagentBatchPreparationService
{
    public function __construct(
        private readonly SubagentLaunchPreparationService $launchPreparation,
        private readonly DeferredSubagentBatchIdentityFactory $identityFactory,
        private readonly ChildRunArtifactLifecycleService $artifactLifecycle,
        private readonly DeferredSubagentBatchRuntimeStartService $runtimeStart,
    ) {
    }

    public function assertLaunchAllowed(string $parentRunId): void
    {
        $this->launchPreparation->assertDepthAllowed($parentRunId);
    }

    public function batchLifecycleId(string $parentRunId, string $toolCallId): string
    {
        return $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId);
    }

    /**
     * @param list<SubagentTaskDTO> $tasks
     *
     * @return list<DeferredSubagentBatchChildIntentDTO>
     */
    public function buildChildIntents(
        string $parentRunId,
        string $toolCallId,
        array $tasks,
        ChildRunBatchExecutionModeEnum $executionMode,
    ): array {
        $intents = [];
        foreach ($tasks as $index => $taskDto) {
            $batchIndex = $index + 1;
            $agentName = $taskDto->trimmedAgent();
            $taskText = $taskDto->trimmedTask();
            $definition = ChildRunBatchExecutionModeEnum::Single === $executionMode
                ? $this->launchPreparation->requireForegroundDefinition($agentName)
                : $this->launchPreparation->requireParallelDefinition($agentName);
            $ids = $this->identityFactory->childIdentity($parentRunId, $toolCallId, $batchIndex);
            $intents[] = new DeferredSubagentBatchChildIntentDTO(
                batchIndex: $batchIndex,
                childRunId: $ids['childRunId'],
                artifactId: $ids['artifactId'],
                agentName: $agentName,
                task: $taskText,
                definitionModel: $definition->model,
            );
        }

        return $intents;
    }

    /**
     * @param list<DeferredSubagentBatchChildIntentDTO> $childIntents
     *
     * @return array<int, AgentDefinitionDTO>
     */
    public function resolveDefinitionsByIndex(array $childIntents, ChildRunBatchExecutionModeEnum $executionMode): array
    {
        $definitions = [];
        foreach ($childIntents as $intent) {
            $definitions[$intent->batchIndex] = ChildRunBatchExecutionModeEnum::Single === $executionMode
                ? $this->launchPreparation->requireForegroundDefinition($intent->agentName)
                : $this->launchPreparation->requireParallelDefinition($intent->agentName);
        }

        return $definitions;
    }

    /**
     * @param list<DeferredSubagentBatchChildIntentDTO> $childIntents
     *
     * @return list<ChildRunIdentityDTO>
     */
    public function buildIdentities(string $parentRunId, array $childIntents): array
    {
        $identities = [];
        foreach ($childIntents as $intent) {
            $identities[] = new ChildRunIdentityDTO(
                parentRunId: $parentRunId,
                childRunId: $intent->childRunId,
                artifactId: $intent->artifactId,
                displayName: $intent->agentName,
                taskSummary: $intent->task,
                definitionModel: $intent->definitionModel,
                artifactKind: AgentArtifactKindEnum::Subagent,
                batchIndex: $intent->batchIndex,
            );
        }

        return $identities;
    }

    /**
     * @param list<DeferredSubagentBatchChildIntentDTO> $childIntents
     * @param array<int, AgentDefinitionDTO>            $definitions
     *
     * @throws DeferredSubagentBatchPreparationFailure
     */
    public function preparePendingChildren(
        string $parentRunId,
        string $toolCallId,
        DeferredSubagentBatchProjectionDTO $projection,
        array $childIntents,
        array $definitions,
    ): DeferredSubagentBatchPreparationResultDTO {
        $identities = $this->buildIdentities($parentRunId, $childIntents);
        $preparedChildren = [];

        foreach ($childIntents as $intent) {
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

            $identity = $this->identityForIndex($identities, $intent->batchIndex);
            $definition = $definitions[$intent->batchIndex];
            try {
                $this->launchPreparation->reserveIdentity($identity);
                $prepared = $this->launchPreparation->prepareFromDefinition(
                    $parentRunId,
                    $definition,
                    $intent->agentName,
                    $intent->task,
                    $intent->artifactId,
                    $intent->childRunId,
                    skipReservation: true,
                    identityTemplate: $identity,
                );
                $this->artifactLifecycle->ensureReservedPending($identity);
                $preparedChildren[] = $prepared;
            } catch (\Throwable $e) {
                $this->runtimeStart->abortAfterPreparationFailure(
                    $parentRunId,
                    $toolCallId,
                    $identities,
                    $e,
                    $intent->batchIndex,
                );

                throw new DeferredSubagentBatchPreparationFailure($intent->batchIndex, $e);
            }
        }

        return new DeferredSubagentBatchPreparationResultDTO($identities, $preparedChildren);
    }

    /**
     * @param list<DeferredSubagentChildProjectionDTO> $children
     */
    public function childProjectionForIndex(array $children, int $batchIndex): ?DeferredSubagentChildProjectionDTO
    {
        foreach ($children as $child) {
            if ($child->batchIndex === $batchIndex) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @param list<DeferredSubagentBatchChildIntentDTO> $childIntents
     * @param list<PreparedAgentChildRunDTO>            $preparedChildren
     *
     * @return list<int>
     */
    public function collectLaunchedBatchIndices(
        string $parentRunId,
        DeferredSubagentBatchProjectionDTO $projection,
        array $childIntents,
        array $preparedChildren,
    ): array {
        $preparedIndexes = [];
        foreach ($preparedChildren as $prepared) {
            $preparedIndexes[$prepared->identity->batchIndex] = true;
        }

        $launched = [];
        foreach ($childIntents as $intent) {
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
