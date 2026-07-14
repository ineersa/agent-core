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
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
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
        private readonly DeferredSubagentChildRepository $childRepository,
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
            return new DeferredToolCompletionOutcome($lifecycleId);
        }

        $startedAt = Clock::get()->now();
        try {
            $this->runtimeStart->startPreparedInOrder($parentRunId, $toolCallId, $identities, $preparedChildren);
        } catch (DeferredSubagentBatchRuntimeStartFailure $e) {
            $failureIndex = $e->failureBatchIndex;
            foreach ($preparedChildren as $prepared) {
                if ($prepared->identity->batchIndex < $failureIndex) {
                    $this->reconcileChildLaunched($parentRunId, $toolCallId, $lifecycleId, $prepared->identity->batchIndex, $startedAt);
                }
            }
            $this->markBatchLaunchFailed($parentRunId, $toolCallId, $childIntents, $failureIndex);

            throw new ToolCallException('Subagent batch launch failed.', retryable: false, previous: $e->getPrevious() ?? $e);
        }

        foreach ($preparedChildren as $prepared) {
            $this->reconcileChildLaunched($parentRunId, $toolCallId, $lifecycleId, $prepared->identity->batchIndex, $startedAt);
        }

        $this->reconcileBatchLaunched($parentRunId, $toolCallId, $startedAt);

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
                $this->markBatchLaunchFailed($parentRunId, $toolCallId, $childIntents, $identity->batchIndex);

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

    private function reconcileBatchLaunched(string $parentRunId, string $toolCallId, \DateTimeImmutable $startedAt): void
    {
        try {
            $this->batchRepository->markLaunched($parentRunId, $toolCallId, $startedAt);
        } catch (\Throwable $e) {
            $this->logger->warning('deferred_subagent_batch.projection_launched_persist_failed', [
                'run_id' => $parentRunId,
                'tool_call_id' => $toolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.projection_launched_persist_failed',
                'exception_class' => $e::class,
            ]);
        }
    }

    private function reconcileChildLaunched(
        string $parentRunId,
        string $toolCallId,
        string $lifecycleId,
        int $batchIndex,
        \DateTimeImmutable $startedAt,
    ): void {
        try {
            $this->childRepository->markChildLaunched($lifecycleId, $batchIndex, $startedAt);
        } catch (\Throwable $e) {
            $this->logger->warning('deferred_subagent_batch.child_launched_persist_failed', [
                'run_id' => $parentRunId,
                'tool_call_id' => $toolCallId,
                'batch_index' => $batchIndex,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.child_launched_persist_failed',
                'exception_class' => $e::class,
            ]);
        }
    }

    /**
     * @param list<array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, definitionModel: ?string}> $childIntents
     */
    private function markBatchLaunchFailed(
        string $parentRunId,
        string $toolCallId,
        array $childIntents,
        int $failureBatchIndex,
    ): void {
        try {
            $this->batchRepository->markFailed($parentRunId, $toolCallId);
        } catch (\Throwable $e) {
            $this->logger->warning('deferred_subagent_batch.projection_mark_failed_failed', [
                'run_id' => $parentRunId,
                'tool_call_id' => $toolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.projection_mark_failed_failed',
                'exception_class' => $e::class,
            ]);
        }

        $lifecycleId = $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        foreach ($childIntents as $intent) {
            if ($intent['batchIndex'] >= $failureBatchIndex) {
                try {
                    $this->childRepository->markChildFailed($lifecycleId, $intent['batchIndex']);
                } catch (\Throwable $e) {
                    $this->logger->warning('deferred_subagent_batch.child_mark_failed_failed', [
                        'run_id' => $parentRunId,
                        'tool_call_id' => $toolCallId,
                        'batch_index' => $intent['batchIndex'],
                        'component' => 'agent.execution',
                        'event_type' => 'deferred_subagent_batch.child_mark_failed_failed',
                        'exception_class' => $e::class,
                    ]);
                }
            }
        }
    }

    private function isArtifactBeyondPending(?AgentArtifactStatusEnum $status): bool
    {
        if (null === $status) {
            return false;
        }

        return AgentArtifactStatusEnum::Pending !== $status;
    }
}
