<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;
use Ineersa\CodingAgent\Agent\Execution\SubagentTaskDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Durable idempotent deferred subagent batch launch (Piece 4A foundation).
 *
 * Not wired to production executeParallel until Piece 4C.
 */
final class DeferredSubagentBatchLaunchService
{
    public function __construct(
        private readonly SubagentLaunchPreparationService $launchPreparation,
        private readonly DeferredSubagentBatchIdentityFactory $identityFactory,
        private readonly DeferredSubagentBatchRepository $batchRepository,
        private readonly ChildRunArtifactLifecycleService $artifactLifecycle,
        private readonly DeferredSubagentBatchRuntimeStartService $runtimeStart,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly AgentsConfig $agentsConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<SubagentTaskDTO> $tasks
     */
    public function launch(
        string $parentRunId,
        array $tasks,
        ChildRunBatchExecutionModeEnum $executionMode,
    ): DeferredToolCompletionOutcome {
        if ([] === $tasks) {
            throw new ToolCallException('Deferred subagent batch launch requires at least one task.', retryable: false);
        }

        $maxAgents = $this->agentsConfig->maxAgents;
        $taskCount = \count($tasks);
        if ($taskCount > $maxAgents) {
            throw new ToolCallException(\sprintf('Parallel subagent execution supports at most %d agents per tool call, but %d tasks were requested.', $maxAgents, $taskCount), retryable: false, hint: \sprintf('Split the work into multiple subagent calls with at most %d tasks each.', $maxAgents));
        }

        $this->launchPreparation->assertDepthAllowed($parentRunId);

        $toolContext = $this->contextAccessor->requireCurrent();
        if ($parentRunId !== $toolContext->runId()) {
            throw new ToolCallException('Subagent parent run id does not match active tool context.', retryable: false);
        }

        $toolCallId = $toolContext->toolCallId();
        $lifecycleId = $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $deadlineAt = Clock::get()->now()->modify(\sprintf('+%d seconds', $this->agentsConfig->subagentToolTimeoutSeconds));

        $childIntents = [];
        $definitions = [];
        foreach ($tasks as $index => $taskDto) {
            $batchIndex = $index + 1;
            $agentName = $taskDto->trimmedAgent();
            $taskText = $taskDto->trimmedTask();
            $definition = ChildRunBatchExecutionModeEnum::Single === $executionMode
                ? $this->launchPreparation->requireForegroundDefinition($agentName)
                : $this->launchPreparation->requireParallelDefinition($agentName);
            $ids = $this->identityFactory->childIdentity($parentRunId, $toolCallId, $batchIndex);
            $childIntents[] = [
                'batchIndex' => $batchIndex,
                'childRunId' => $ids['childRunId'],
                'artifactId' => $ids['artifactId'],
                'agentName' => $agentName,
                'task' => $taskText,
                'definitionModel' => $definition->model,
            ];
            $definitions[$batchIndex] = $definition;
        }

        $existing = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId);
        if (null !== $existing && DeferredSubagentBatchLaunchStatusEnum::Failed === $existing->launchStatus) {
            throw new ToolCallException('Subagent batch launch previously failed for this tool call.', retryable: false);
        }

        if (null !== $existing && DeferredSubagentBatchLaunchStatusEnum::Launched === $existing->launchStatus) {
            $this->batchRepository->reserveBatch(
                lifecycleId: $lifecycleId,
                parentRunId: $parentRunId,
                parentTurnNo: $toolContext->turnNo(),
                parentToolCallId: $toolCallId,
                parentOrderIndex: $toolContext->orderIndex(),
                executionMode: $executionMode,
                totalChildCount: $taskCount,
                deadlineAt: $deadlineAt,
                childIntents: $childIntents,
            );

            return new DeferredToolCompletionOutcome($lifecycleId);
        }

        $projection = $this->batchRepository->reserveBatch(
            lifecycleId: $lifecycleId,
            parentRunId: $parentRunId,
            parentTurnNo: $toolContext->turnNo(),
            parentToolCallId: $toolCallId,
            parentOrderIndex: $toolContext->orderIndex(),
            executionMode: $executionMode,
            totalChildCount: $taskCount,
            deadlineAt: $deadlineAt,
            childIntents: $childIntents,
        );

        $identities = $this->buildIdentities($parentRunId, $childIntents);
        $preparedChildren = $this->prepareChildren(
            $parentRunId,
            $toolCallId,
            $projection,
            $childIntents,
            $definitions,
            $identities,
        );

        if ([] === $preparedChildren) {
            $this->reconcileLaunchSuccessFromArtifacts($parentRunId, $toolCallId, $lifecycleId, $projection, $childIntents);

            return new DeferredToolCompletionOutcome($lifecycleId);
        }

        $startedAt = Clock::get()->now();
        try {
            $this->runtimeStart->startPreparedInOrder($parentRunId, $toolCallId, $identities, $preparedChildren);
        } catch (DeferredSubagentBatchRuntimeStartFailure $e) {
            $failureIndex = $e->failureBatchIndex;
            $launchedBeforeFailure = [];
            foreach ($preparedChildren as $prepared) {
                if ($prepared->identity->batchIndex < $failureIndex) {
                    $launchedBeforeFailure[] = $prepared->identity->batchIndex;
                }
            }
            try {
                $this->batchRepository->applyLaunchFailureRuntime(
                    $parentRunId,
                    $toolCallId,
                    $lifecycleId,
                    $failureIndex,
                    $launchedBeforeFailure,
                );
            } catch (\Throwable $persistFailure) {
                $this->logger->warning('deferred_subagent_batch.launch_failure_persist_failed', [
                    'run_id' => $parentRunId,
                    'tool_call_id' => $toolCallId,
                    'component' => 'agent.execution',
                    'event_type' => 'deferred_subagent_batch.launch_failure_persist_failed',
                    'failure_phase' => 'runtime_start',
                    'failure_batch_index' => $failureIndex,
                    'exception_class' => $persistFailure::class,
                ]);
            }

            throw new ToolCallException('Subagent batch launch failed.', retryable: false, previous: $e->getPrevious() ?? $e);
        }

        $launchedIndices = $this->collectLaunchedBatchIndices($parentRunId, $projection, $childIntents, $preparedChildren);
        try {
            $this->batchRepository->applyLaunchSuccessState($parentRunId, $toolCallId, $lifecycleId, $startedAt, $launchedIndices);
        } catch (\Throwable $persistFailure) {
            $this->logger->warning('deferred_subagent_batch.launch_success_persist_failed', [
                'run_id' => $parentRunId,
                'tool_call_id' => $toolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.launch_success_persist_failed',
                'exception_class' => $persistFailure::class,
            ]);
        }

        return new DeferredToolCompletionOutcome($lifecycleId);
    }

    /**
     * @param list<array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, definitionModel: ?string}> $childIntents
     *
     * @return list<ChildRunIdentityDTO>
     */
    private function buildIdentities(string $parentRunId, array $childIntents): array
    {
        $identities = [];
        foreach ($childIntents as $intent) {
            $identities[] = new ChildRunIdentityDTO(
                parentRunId: $parentRunId,
                childRunId: $intent['childRunId'],
                artifactId: $intent['artifactId'],
                displayName: $intent['agentName'],
                taskSummary: $intent['task'],
                definitionModel: $intent['definitionModel'],
                artifactKind: AgentArtifactKindEnum::Subagent,
                batchIndex: $intent['batchIndex'],
            );
        }

        return $identities;
    }

    /**
     * @param list<array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, definitionModel: ?string}> $childIntents
     * @param array<int, \Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO>                                                            $definitions
     * @param list<ChildRunIdentityDTO>                                                                                                       $identities
     *
     * @return list<PreparedAgentChildRunDTO>
     */
    private function prepareChildren(
        string $parentRunId,
        string $toolCallId,
        DeferredSubagentBatchProjectionDTO $projection,
        array $childIntents,
        array $definitions,
        array $identities,
    ): array {
        $preparedChildren = [];

        foreach ($childIntents as $intent) {
            $identity = $this->identityForIndex($identities, $intent['batchIndex']);
            $childProjection = $this->childProjectionForIndex($projection->children, $intent['batchIndex']);

            if (null !== $childProjection && DeferredSubagentChildLaunchStatusEnum::Launched === $childProjection->launchStatus) {
                continue;
            }

            if (null !== $childProjection && DeferredSubagentChildLaunchStatusEnum::Failed === $childProjection->launchStatus) {
                continue;
            }

            $artifactStatus = $this->artifactLifecycle->getArtifactStatus($parentRunId, $intent['artifactId']);
            if ($this->isArtifactBeyondPending($artifactStatus)) {
                continue;
            }

            $definition = $definitions[$intent['batchIndex']];
            try {
                $this->launchPreparation->reserveIdentity($identity);
                $prepared = $this->launchPreparation->prepareFromDefinition(
                    $parentRunId,
                    $definition,
                    $intent['agentName'],
                    $intent['task'],
                    $intent['artifactId'],
                    $intent['childRunId'],
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
                    $identity->batchIndex,
                );
                try {
                    $this->batchRepository->applyLaunchFailurePreparation($parentRunId, $toolCallId, $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId));
                } catch (\Throwable $persistFailure) {
                    $this->logger->warning('deferred_subagent_batch.launch_failure_persist_failed', [
                        'run_id' => $parentRunId,
                        'tool_call_id' => $toolCallId,
                        'component' => 'agent.execution',
                        'event_type' => 'deferred_subagent_batch.launch_failure_persist_failed',
                        'failure_phase' => 'preparation',
                        'exception_class' => $persistFailure::class,
                    ]);
                }

                throw new ToolCallException('Subagent batch launch failed.', retryable: false, previous: $e);
            }
        }

        return $preparedChildren;
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
     * Retry path: children already running in artifact/projection while batch row is still Reserved.
     *
     * @param list<array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, definitionModel: ?string}> $childIntents
     */
    private function reconcileLaunchSuccessFromArtifacts(
        string $parentRunId,
        string $toolCallId,
        string $lifecycleId,
        DeferredSubagentBatchProjectionDTO $projection,
        array $childIntents,
    ): void {
        $launchedIndices = $this->collectLaunchedBatchIndices($parentRunId, $projection, $childIntents, []);
        if ([] === $launchedIndices) {
            return;
        }

        try {
            $this->batchRepository->applyLaunchSuccessState(
                $parentRunId,
                $toolCallId,
                $lifecycleId,
                Clock::get()->now(),
                $launchedIndices,
            );
        } catch (\Throwable $persistFailure) {
            $this->logger->warning('deferred_subagent_batch.launch_success_persist_failed', [
                'run_id' => $parentRunId,
                'tool_call_id' => $toolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.launch_success_persist_failed',
                'exception_class' => $persistFailure::class,
            ]);
        }
    }

    /**
     * @param list<array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, definitionModel: ?string}> $childIntents
     * @param list<PreparedAgentChildRunDTO>                                                                                                  $preparedChildren
     *
     * @return list<int>
     */
    private function collectLaunchedBatchIndices(
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
            $childProjection = $this->childProjectionForIndex($projection->children, $intent['batchIndex']);
            if (null !== $childProjection && DeferredSubagentChildLaunchStatusEnum::Launched === $childProjection->launchStatus) {
                $launched[] = $intent['batchIndex'];

                continue;
            }

            if (isset($preparedIndexes[$intent['batchIndex']])) {
                $launched[] = $intent['batchIndex'];

                continue;
            }

            $artifactStatus = $this->artifactLifecycle->getArtifactStatus($parentRunId, $intent['artifactId']);
            if ($this->isArtifactBeyondPending($artifactStatus)) {
                $launched[] = $intent['batchIndex'];
            }
        }

        return $launched;
    }

    private function isArtifactBeyondPending(?AgentArtifactStatusEnum $status): bool
    {
        if (null === $status) {
            return false;
        }

        return AgentArtifactStatusEnum::Pending !== $status;
    }
}
