<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLaunchAbortContextDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Psr\Log\LoggerInterface;

/**
 * Durable idempotent single-child launch returning deferred tool completion (Piece 3A).
 */
final class DeferredSingleSubagentLaunchService
{
    public function __construct(
        private readonly SubagentLaunchPreparationService $launchPreparation,
        private readonly DeferredSingleSubagentIdentityFactory $identityFactory,
        private readonly DeferredSingleSubagentLaunchRepository $launchProjectionRepository,
        private readonly ChildRunArtifactLifecycleService $artifactLifecycle,
        private readonly AgentRunnerInterface $agentRunner,
        private readonly ChildRunBatchLaunchService $batchLaunchService,
        private readonly SubagentChildRunBatchLifecyclePolicyFactory $lifecyclePolicyFactory,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly AgentsConfig $agentsConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function launch(
        string $parentRunId,
        string $agentName,
        string $task,
    ): DeferredToolCompletionOutcome {
        $definition = $this->launchPreparation->requireForegroundDefinition($agentName);
        $this->launchPreparation->assertDepthAllowed($parentRunId);

        $toolContext = $this->contextAccessor->requireCurrent();
        if ($parentRunId !== $toolContext->runId()) {
            throw new ToolCallException('Subagent parent run id does not match active tool context.', retryable: false);
        }

        $toolCallId = $toolContext->toolCallId();
        $ids = $this->identityFactory->forParentToolCall($parentRunId, $toolCallId);
        $deadlineAt = (new \DateTimeImmutable())->modify(\sprintf('+%d seconds', $this->agentsConfig->subagentToolTimeoutSeconds));

        $existing = $this->launchProjectionRepository->findByParentRunAndToolCall($parentRunId, $toolCallId);

        if (null !== $existing && DeferredSingleSubagentLaunchStatusEnum::Failed === $existing->launchStatus) {
            throw new ToolCallException('Subagent child launch previously failed for this tool call.', retryable: false);
        }

        $projection = $this->launchProjectionRepository->reserve(
            parentRunId: $parentRunId,
            parentTurnNo: $toolContext->turnNo(),
            parentToolCallId: $toolCallId,
            parentOrderIndex: $toolContext->orderIndex(),
            childRunId: null !== $existing ? $existing->childRunId : $ids['childRunId'],
            artifactId: null !== $existing ? $existing->artifactId : $ids['artifactId'],
            agentName: $agentName,
            task: $task,
            definitionModel: $definition->model,
            deadlineAt: $deadlineAt,
        );

        $prepared = $this->launchPreparation->prepareFromDefinition(
            $parentRunId,
            $definition,
            $agentName,
            $task,
            $projection->artifactId,
            $projection->childRunId,
            skipReservation: true,
        );
        $this->artifactLifecycle->ensureReservedPending($prepared->identity);

        return $this->dispatchLaunch($parentRunId, $toolCallId, $prepared);
    }

    private function dispatchLaunch(
        string $parentRunId,
        string $toolCallId,
        PreparedAgentChildRunDTO $prepared,
    ): DeferredToolCompletionOutcome {
        $artifactStatus = $this->artifactLifecycle->getArtifactStatus($parentRunId, $prepared->identity->artifactId);
        if ($this->isArtifactBeyondPending($artifactStatus)) {
            $this->reconcileLaunchedProjection($parentRunId, $toolCallId);

            return new DeferredToolCompletionOutcome();
        }

        try {
            $this->agentRunner->start($prepared->startRunInput);
        } catch (\Throwable $e) {
            $policy = $this->lifecyclePolicyFactory->create();
            try {
                $this->batchLaunchService->abort(
                    $parentRunId,
                    [$prepared->identity],
                    $policy,
                    $e,
                    ChildRunBatchLaunchAbortContextDTO::runtimeStart(),
                );
            } catch (\Throwable $abortFailure) {
                $this->logger->warning('deferred_single_subagent.launch_abort_failed', [
                    'run_id' => $parentRunId,
                    'tool_call_id' => $toolCallId,
                    'child_run_id' => $prepared->identity->childRunId,
                    'artifact_id' => $prepared->identity->artifactId,
                    'component' => 'agent.execution',
                    'event_type' => 'deferred_single_subagent.launch_abort_failed',
                    'exception_class' => $abortFailure::class,
                    'message' => $abortFailure->getMessage(),
                ]);
            }

            try {
                $this->launchProjectionRepository->markFailed($parentRunId, $toolCallId);
            } catch (\Throwable $markFailedFailure) {
                $this->logger->warning('deferred_single_subagent.projection_mark_failed_failed', [
                    'run_id' => $parentRunId,
                    'tool_call_id' => $toolCallId,
                    'child_run_id' => $prepared->identity->childRunId,
                    'artifact_id' => $prepared->identity->artifactId,
                    'component' => 'agent.execution',
                    'event_type' => 'deferred_single_subagent.projection_mark_failed_failed',
                    'exception_class' => $markFailedFailure::class,
                    'message' => $markFailedFailure->getMessage(),
                ]);
            }

            throw new ToolCallException('Subagent child launch failed: '.$e->getMessage(), retryable: false, previous: $e);
        }

        // start() returned: child StartRun is idempotently dispatched; do not abort/cancel on persistence failures.
        $this->reconcilePostDispatch($parentRunId, $toolCallId, $prepared);

        return new DeferredToolCompletionOutcome();
    }

    private function reconcilePostDispatch(
        string $parentRunId,
        string $toolCallId,
        PreparedAgentChildRunDTO $prepared,
    ): void {
        try {
            $this->artifactLifecycle->markRunning($prepared->identity);
        } catch (\Throwable $e) {
            $this->logger->warning('deferred_single_subagent.artifact_running_persist_failed', [
                'run_id' => $parentRunId,
                'tool_call_id' => $toolCallId,
                'child_run_id' => $prepared->identity->childRunId,
                'artifact_id' => $prepared->identity->artifactId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_single_subagent.artifact_running_persist_failed',
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $this->launchProjectionRepository->markLaunched($parentRunId, $toolCallId, new \DateTimeImmutable());
        } catch (\Throwable $e) {
            $this->logger->warning('deferred_single_subagent.projection_launched_persist_failed', [
                'run_id' => $parentRunId,
                'tool_call_id' => $toolCallId,
                'child_run_id' => $prepared->identity->childRunId,
                'artifact_id' => $prepared->identity->artifactId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_single_subagent.projection_launched_persist_failed',
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function reconcileLaunchedProjection(string $parentRunId, string $toolCallId): void
    {
        try {
            $this->launchProjectionRepository->markLaunched($parentRunId, $toolCallId, new \DateTimeImmutable());
        } catch (\Throwable $e) {
            $this->logger->warning('deferred_single_subagent.projection_reconcile_failed', [
                'run_id' => $parentRunId,
                'tool_call_id' => $toolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_single_subagent.projection_reconcile_failed',
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ]);
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
