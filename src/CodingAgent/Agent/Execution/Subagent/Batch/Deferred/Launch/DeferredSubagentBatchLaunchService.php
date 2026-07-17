<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentChildPreparationStrategyInterface;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentSingleChildLaunchProfileDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentTaskDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Durable idempotent deferred subagent batch launch for single and parallel tool calls.
 */
final class DeferredSubagentBatchLaunchService
{
    public function __construct(
        private readonly DeferredSubagentBatchPreparationService $batchPreparation,
        private readonly DeferredSubagentBatchRepository $batchRepository,
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
        ?DeferredSubagentSingleChildLaunchProfileDTO $singleChildProfile = null,
        ?DeferredSubagentChildPreparationStrategyInterface $preparationStrategy = null,
    ): DeferredToolCompletionOutcome {
        // Capture correlation while tool stack context is active; continueReserved must not require it.
        $toolCallId = $this->contextAccessor->requireCurrent()->toolCallId();
        $reserved = $this->reserveOnly($parentRunId, $tasks, $executionMode, $singleChildProfile);
        $this->continueReserved(
            $parentRunId,
            $toolCallId,
            $tasks,
            $executionMode,
            $singleChildProfile,
            $preparationStrategy,
        );

        return $reserved;
    }

    /**
     * Reserve durable batch identity without preparing/starting children.
     * Requires active tool stack context (parent tool call correlation).
     *
     * @param list<SubagentTaskDTO> $tasks
     */
    public function reserveOnly(
        string $parentRunId,
        array $tasks,
        ChildRunBatchExecutionModeEnum $executionMode,
        ?DeferredSubagentSingleChildLaunchProfileDTO $singleChildProfile = null,
    ): DeferredToolCompletionOutcome {
        $this->assertTaskShape($tasks, $executionMode);

        $toolContext = $this->contextAccessor->requireCurrent();
        if ($parentRunId !== $toolContext->runId()) {
            throw new ToolCallException('Subagent parent run id does not match active tool context.', retryable: false);
        }

        $toolCallId = $toolContext->toolCallId();
        $plan = $this->batchPreparation->buildLaunchPlan($parentRunId, $toolCallId, $tasks, $executionMode, $singleChildProfile);
        $lifecycleId = $plan->lifecycleId;
        $taskCount = \count($tasks);
        $deadlineAt = Clock::get()->now()->modify(\sprintf('+%d seconds', $this->agentsConfig->subagentToolTimeoutSeconds));

        $existing = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId);
        if (null !== $existing && DeferredSubagentBatchLaunchStatusEnum::Failed === $existing->launchStatus) {
            throw new ToolCallException('Subagent batch launch previously failed for this tool call.', retryable: false);
        }

        $this->batchRepository->reserveBatch(
            lifecycleId: $lifecycleId,
            parentRunId: $parentRunId,
            parentTurnNo: $toolContext->turnNo(),
            parentToolCallId: $toolCallId,
            parentOrderIndex: $toolContext->orderIndex(),
            executionMode: $executionMode,
            totalChildCount: $taskCount,
            deadlineAt: $deadlineAt,
            childIntents: $plan->reserveChildIntents(),
        );

        return new DeferredToolCompletionOutcome($lifecycleId);
    }

    /**
     * Prepare and start children for an already-reserved batch (idempotent).
     * Uses explicit parent tool-call correlation — safe after tool stack context is gone
     * (async fork compaction continuation).
     *
     * @param list<SubagentTaskDTO> $tasks
     */
    public function continueReserved(
        string $parentRunId,
        string $parentToolCallId,
        array $tasks,
        ChildRunBatchExecutionModeEnum $executionMode,
        ?DeferredSubagentSingleChildLaunchProfileDTO $singleChildProfile = null,
        ?DeferredSubagentChildPreparationStrategyInterface $preparationStrategy = null,
    ): void {
        $this->assertTaskShape($tasks, $executionMode);

        if ('' === trim($parentToolCallId)) {
            throw new ToolCallException('Subagent continueReserved requires parent tool call id.', retryable: false);
        }

        $plan = $this->batchPreparation->buildLaunchPlan($parentRunId, $parentToolCallId, $tasks, $executionMode, $singleChildProfile);
        $lifecycleId = $plan->lifecycleId;

        $existing = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $parentToolCallId);
        if (null === $existing) {
            throw new ToolCallException('Subagent batch must be reserved before continueReserved.', retryable: false);
        }
        if (DeferredSubagentBatchLaunchStatusEnum::Failed === $existing->launchStatus) {
            throw new ToolCallException('Subagent batch launch previously failed for this tool call.', retryable: false);
        }
        if (DeferredSubagentBatchLaunchStatusEnum::Launched === $existing->launchStatus) {
            return;
        }

        $projection = $existing;

        try {
            $preparedChildren = $this->batchPreparation->preparePendingChildren($parentRunId, $projection, $plan, $singleChildProfile, $preparationStrategy);
        } catch (DeferredSubagentBatchPreparationFailure $e) {
            $this->runtimeStart->abortAfterPreparationFailure(
                $parentRunId,
                $parentToolCallId,
                $plan->identities,
                $e->getPrevious() ?? $e,
                $e->failureBatchIndex,
            );
            try {
                $this->batchRepository->applyLaunchFailurePreparation($parentRunId, $parentToolCallId, $lifecycleId);
            } catch (\Throwable $persistFailure) {
                $this->logger->warning('deferred_subagent_batch.launch_failure_persist_failed', [
                    'run_id' => $parentRunId,
                    'tool_call_id' => $parentToolCallId,
                    'component' => 'agent.execution',
                    'event_type' => 'deferred_subagent_batch.launch_failure_persist_failed',
                    'failure_phase' => 'preparation',
                    'failure_batch_index' => $e->failureBatchIndex,
                    'exception_class' => $persistFailure::class,
                ]);
            }

            throw new ToolCallException('Subagent batch launch failed.', retryable: false, previous: $e->getPrevious() ?? $e);
        }

        if ([] === $preparedChildren) {
            $this->reconcileLaunchSuccessFromArtifacts($parentRunId, $parentToolCallId, $lifecycleId, $projection, $plan);

            return;
        }

        $startedAt = Clock::get()->now();
        try {
            $this->runtimeStart->startPreparedInOrder(
                $parentRunId,
                $parentToolCallId,
                $plan->identities,
                $preparedChildren,
            );
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
                    $parentToolCallId,
                    $lifecycleId,
                    $failureIndex,
                    $launchedBeforeFailure,
                );
            } catch (\Throwable $persistFailure) {
                $this->logger->warning('deferred_subagent_batch.launch_failure_persist_failed', [
                    'run_id' => $parentRunId,
                    'tool_call_id' => $parentToolCallId,
                    'component' => 'agent.execution',
                    'event_type' => 'deferred_subagent_batch.launch_failure_persist_failed',
                    'failure_phase' => 'runtime_start',
                    'failure_batch_index' => $failureIndex,
                    'exception_class' => $persistFailure::class,
                ]);
            }

            throw new ToolCallException('Subagent batch launch failed.', retryable: false, previous: $e->getPrevious() ?? $e);
        }

        $launchedIndices = $this->batchPreparation->collectLaunchedBatchIndices(
            $parentRunId,
            $projection,
            $plan,
            $preparedChildren,
        );
        try {
            $this->batchRepository->applyLaunchSuccessState($parentRunId, $parentToolCallId, $lifecycleId, $startedAt, $launchedIndices);
        } catch (\Throwable $persistFailure) {
            $this->logger->warning('deferred_subagent_batch.launch_success_persist_failed', [
                'run_id' => $parentRunId,
                'tool_call_id' => $parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.launch_success_persist_failed',
                'exception_class' => $persistFailure::class,
            ]);
        }
    }

    /**
     * @param list<SubagentTaskDTO> $tasks
     */
    private function assertTaskShape(array $tasks, ChildRunBatchExecutionModeEnum $executionMode): void
    {
        if (ChildRunBatchExecutionModeEnum::Single === $executionMode && 1 !== \count($tasks)) {
            throw new ToolCallException('Single subagent requires exactly one task.', retryable: false);
        }

        if ([] === $tasks) {
            throw new ToolCallException('Deferred subagent batch launch requires at least one task.', retryable: false);
        }

        $maxAgents = $this->agentsConfig->maxAgents;
        $taskCount = \count($tasks);
        if ($taskCount > $maxAgents) {
            throw new ToolCallException(\sprintf('Parallel subagent execution supports at most %d agents per tool call, but %d tasks were requested.', $maxAgents, $taskCount), retryable: false, hint: \sprintf('Split the work into multiple subagent calls with at most %d tasks each.', $maxAgents));
        }
    }

    private function reconcileLaunchSuccessFromArtifacts(
        string $parentRunId,
        string $toolCallId,
        string $lifecycleId,
        DeferredSubagentBatchProjectionDTO $projection,
        DeferredSubagentBatchLaunchPlanDTO $plan,
    ): void {
        $launchedIndices = $this->batchPreparation->collectLaunchedBatchIndices($parentRunId, $projection, $plan, []);
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
}
