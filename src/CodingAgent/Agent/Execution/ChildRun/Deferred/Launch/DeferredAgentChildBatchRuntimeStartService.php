<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLaunchAbortContextDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLifecyclePolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory;
use Psr\Log\LoggerInterface;

/**
 * Ordered runtime child starts and canonical launch-abort for deferred batch launch.
 */
final class DeferredAgentChildBatchRuntimeStartService
{
    public function __construct(
        private readonly AgentRunnerInterface $agentRunner,
        private readonly ChildRunArtifactLifecycleService $artifactLifecycle,
        private readonly ChildRunBatchLaunchService $batchLaunchService,
        private readonly SubagentChildRunBatchLifecyclePolicyFactory $lifecyclePolicyFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<ChildRunIdentityDTO>      $identities
     * @param list<PreparedAgentChildRunDTO> $preparedChildren
     *
     * @throws DeferredAgentChildBatchRuntimeStartFailure when a runtime start fails (after abort cleanup)
     */
    public function startPreparedInOrder(
        string $parentRunId,
        string $toolCallId,
        array $identities,
        array $preparedChildren,
    ): void {
        $policy = $this->lifecyclePolicyFactory->create();
        /** @var list<string> $knownStartedChildRunIds */
        $knownStartedChildRunIds = [];

        foreach ($preparedChildren as $prepared) {
            $artifactStatus = $this->artifactLifecycle->getArtifactStatus($parentRunId, $prepared->identity->artifactId);
            if ($this->isArtifactBeyondPending($artifactStatus)) {
                continue;
            }

            try {
                $this->agentRunner->start($prepared->startRunInput);
                $knownStartedChildRunIds[] = $prepared->identity->childRunId;
            } catch (\Throwable $e) {
                $this->abortAfterRuntimeFailure(
                    $parentRunId,
                    $toolCallId,
                    $identities,
                    $policy,
                    $e,
                    $knownStartedChildRunIds,
                );

                throw new DeferredAgentChildBatchRuntimeStartFailure($prepared->identity->batchIndex, $e);
            }

            try {
                $this->artifactLifecycle->markRunning($prepared->identity);
            } catch (\Throwable $markRunningFailure) {
                $this->logger->warning('deferred_subagent_batch.artifact_running_persist_failed', [
                    'run_id' => $parentRunId,
                    'tool_call_id' => $toolCallId,
                    'child_run_id' => $prepared->identity->childRunId,
                    'artifact_id' => $prepared->identity->artifactId,
                    'component' => 'agent.execution',
                    'event_type' => 'deferred_subagent_batch.artifact_running_persist_failed',
                    'exception_class' => $markRunningFailure::class,
                ]);
            }
        }
    }

    /**
     * @param list<ChildRunIdentityDTO> $identities
     */
    public function abortAfterPreparationFailure(
        string $parentRunId,
        string $toolCallId,
        array $identities,
        \Throwable $cause,
        int $failureBatchIndex,
    ): void {
        $policy = $this->lifecyclePolicyFactory->create();
        try {
            $this->batchLaunchService->abort(
                $parentRunId,
                $identities,
                $policy,
                $cause,
                ChildRunBatchLaunchAbortContextDTO::preparationFailure($failureBatchIndex),
            );
        } catch (\Throwable $abortFailure) {
            $this->logger->warning('deferred_subagent_batch.launch_abort_failed', [
                'run_id' => $parentRunId,
                'tool_call_id' => $toolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.launch_abort_failed',
                'abort_phase' => 'preparation',
                'failure_batch_index' => $failureBatchIndex,
                'exception_class' => $abortFailure::class,
            ]);
        }
    }

    /**
     * @param list<ChildRunIdentityDTO> $identities
     * @param list<string>              $knownStartedChildRunIds
     */
    private function abortAfterRuntimeFailure(
        string $parentRunId,
        string $toolCallId,
        array $identities,
        ChildRunBatchLifecyclePolicyDTO $policy,
        \Throwable $cause,
        array $knownStartedChildRunIds,
    ): void {
        try {
            $this->batchLaunchService->abort(
                $parentRunId,
                $identities,
                $policy,
                $cause,
                ChildRunBatchLaunchAbortContextDTO::runtimeStart(),
                $knownStartedChildRunIds,
            );
        } catch (\Throwable $abortFailure) {
            $this->logger->warning('deferred_subagent_batch.launch_abort_failed', [
                'run_id' => $parentRunId,
                'tool_call_id' => $toolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.launch_abort_failed',
                'abort_phase' => 'runtime_start',
                'exception_class' => $abortFailure::class,
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
