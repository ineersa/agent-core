<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLaunchAbortPhaseEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLifecyclePolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLaunchService;
use Psr\Log\LoggerInterface;

/**
 * Ordered runtime child starts and canonical launch-abort for deferred batch launch (Piece 4A).
 */
final class DeferredSubagentBatchRuntimeStartService
{
    public function __construct(
        private readonly AgentRunnerInterface $agentRunner,
        private readonly ChildRunArtifactLifecycleService $artifactLifecycle,
        private readonly ChildRunBatchLaunchService $batchLaunchService,
        private readonly ChildRunBatchLifecyclePolicyDTO $lifecyclePolicy,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws DeferredSubagentBatchRuntimeStartFailure when a runtime start fails (after abort cleanup)
     */
    public function startPrepared(
        string $parentRunId,
        string $toolCallId,
        ChildRunIdentityDTO $identity,
        PreparedAgentChildRunDTO $prepared,
    ): void {
        $artifactStatus = $this->artifactLifecycle->getArtifactStatus($parentRunId, $prepared->identity->artifactId);
        if ($this->isArtifactBeyondPending($artifactStatus)) {
            return;
        }

        try {
            $this->agentRunner->start($prepared->startRunInput);
        } catch (\Throwable $e) {
            $this->abortAfterRuntimeFailure($parentRunId, $toolCallId, $identity, $this->lifecyclePolicy, $e);

            throw new DeferredSubagentBatchRuntimeStartFailure($e);
        }
    }

    public function abortAfterPreparationFailure(
        string $parentRunId,
        string $toolCallId,
        ChildRunIdentityDTO $identity,
        \Throwable $cause,
        int $failureBatchIndex,
    ): void {
        $policy = $this->lifecyclePolicy;
        try {
            $this->batchLaunchService->abort(
                $parentRunId,
                $identity,
                $policy,
                $cause,
                ChildRunBatchLaunchAbortPhaseEnum::Preparation,
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

    private function abortAfterRuntimeFailure(
        string $parentRunId,
        string $toolCallId,
        ChildRunIdentityDTO $identity,
        ChildRunBatchLifecyclePolicyDTO $policy,
        \Throwable $cause,
    ): void {
        try {
            $this->batchLaunchService->abort(
                $parentRunId,
                $identity,
                $policy,
                $cause,
                ChildRunBatchLaunchAbortPhaseEnum::RuntimeStart,
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
