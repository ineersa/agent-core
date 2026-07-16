<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentChildPreparationStrategyInterface;
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
        ?DeferredSubagentChildPreparationStrategyInterface $preparationStrategy = null,
    ): DeferredToolCompletionOutcome {
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

        $toolContext = $this->contextAccessor->requireCurrent();
        if ($parentRunId !== $toolContext->runId()) {
            throw new ToolCallException('Subagent parent run id does not match active tool context.', retryable: false);
        }

        $toolCallId = $toolContext->toolCallId();
        $plan = $this->batchPreparation->buildLaunchPlan($parentRunId, $toolCallId, $tasks, $executionMode);
        $lifecycleId = $plan->lifecycleId;
        $deadlineAt = Clock::get()->now()->modify(\sprintf('+%d seconds', $this->agentsConfig->subagentToolTimeoutSeconds));

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
                childIntents: $plan->reserveChildIntents(),
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
            childIntents: $plan->reserveChildIntents(),
        );

        try {
            $preparedChildren = $this->batchPreparation->preparePendingChildren($parentRunId, $projection, $plan, $preparationStrategy);
        } catch (DeferredSubagentBatchPreparationFailure $e) {
            $this->runtimeStart->abortAfterPreparationFailure(
                $parentRunId,
                $toolCallId,
                $plan->identities,
                $e->getPrevious() ?? $e,
                $e->failureBatchIndex,
            );
            try {
                $this->batchRepository->applyLaunchFailurePreparation($parentRunId, $toolCallId, $lifecycleId);
            } catch (\Throwable $persistFailure) {
                $this->logger->warning('deferred_subagent_batch.launch_failure_persist_failed', [
                    'run_id' => $parentRunId,
                    'tool_call_id' => $toolCallId,
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
            $this->reconcileLaunchSuccessFromArtifacts($parentRunId, $toolCallId, $lifecycleId, $projection, $plan);

            return new DeferredToolCompletionOutcome($lifecycleId);
        }

        $startedAt = Clock::get()->now();
        try {
            $this->runtimeStart->startPreparedInOrder(
                $parentRunId,
                $toolCallId,
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

        $launchedIndices = $this->batchPreparation->collectLaunchedBatchIndices(
            $parentRunId,
            $projection,
            $plan,
            $preparedChildren,
        );
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
