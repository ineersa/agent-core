<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchPreparationFailure;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchRuntimeStartFailure;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchRuntimeStartService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Durable idempotent deferred child batch launch: reserve → prepare → ordered start → persist outcome.
 *
 * Kind-specific preparation is injected; persistence still uses deferred subagent batch tables (semantic rename deferred).
 */
final class DeferredAgentChildBatchLaunchCoordinator
{
    public function __construct(
        private readonly DeferredSubagentBatchRepository $batchRepository,
        private readonly DeferredSubagentBatchRuntimeStartService $runtimeStart,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<AgentChildLaunchTaskInterface> $tasks
     */
    public function launch(
        string $parentRunId,
        array $tasks,
        ChildRunBatchExecutionModeEnum $executionMode,
        DeferredAgentChildBatchPreparationInterface $batchPreparation,
        int $timeoutSeconds,
    ): DeferredToolCompletionOutcome {
        if ([] === $tasks) {
            throw new DeferredAgentChildBatchLaunchException(DeferredAgentChildBatchLaunchFailureReasonEnum::EmptyTasks);
        }

        $toolContext = $this->contextAccessor->requireCurrent();
        if ($parentRunId !== $toolContext->runId()) {
            throw new DeferredAgentChildBatchLaunchException(DeferredAgentChildBatchLaunchFailureReasonEnum::ParentContextMismatch);
        }

        $toolCallId = $toolContext->toolCallId();
        $taskCount = \count($tasks);
        $plan = $batchPreparation->buildLaunchPlan($parentRunId, $toolCallId, $tasks, $executionMode);
        $lifecycleId = $plan->lifecycleId;
        $deadlineAt = Clock::get()->now()->modify(\sprintf('+%d seconds', $timeoutSeconds));

        $existing = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId);
        if (null !== $existing && DeferredSubagentBatchLaunchStatusEnum::Failed === $existing->launchStatus) {
            throw new DeferredAgentChildBatchLaunchException(DeferredAgentChildBatchLaunchFailureReasonEnum::PreviouslyFailed);
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
            $preparedChildren = $batchPreparation->preparePendingChildren($parentRunId, $projection, $plan);
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

            throw new DeferredAgentChildBatchLaunchException(
                DeferredAgentChildBatchLaunchFailureReasonEnum::PreparationFailed,
                $e->getPrevious() ?? $e,
                $e->failureBatchIndex,
            );
        }

        if ([] === $preparedChildren) {
            $this->reconcileLaunchSuccessFromArtifacts($parentRunId, $toolCallId, $lifecycleId, $projection, $plan, $batchPreparation);

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

            throw new DeferredAgentChildBatchLaunchException(
                DeferredAgentChildBatchLaunchFailureReasonEnum::RuntimeStartFailed,
                $e->getPrevious() ?? $e,
                $failureIndex,
            );
        }

        $launchedIndices = $batchPreparation->collectLaunchedBatchIndices(
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
        DeferredAgentChildBatchLaunchPlanDTO $plan,
        DeferredAgentChildBatchPreparationInterface $batchPreparation,
    ): void {
        $launchedIndices = $batchPreparation->collectLaunchedBatchIndices($parentRunId, $projection, $plan, []);
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
