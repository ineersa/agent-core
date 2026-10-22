<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLaunchAbortPhaseEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLifecyclePolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationRequestDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Psr\Log\LoggerInterface;

/**
 * Runtime process start, Running artifact transition, and the canonical launch-abort state machine.
 */
final class ChildRunBatchLaunchService
{
    public function __construct(
        private readonly AgentRunnerInterface $agentRunner,
        private readonly ChildRunArtifactLifecycleService $artifactLifecycle,
        private readonly ChildRunBatchLifecycleListenerInterface $lifecycleListener,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function abort(
        string $parentRunId,
        ChildRunIdentityDTO $identity,
        ChildRunBatchLifecyclePolicyDTO $policy,
        \Throwable $cause,
        ChildRunBatchLaunchAbortPhaseEnum $phase,
    ): void {
        $this->logger->warning('child_run.launch_aborted', [
            'run_id' => $parentRunId,
            'component' => 'agent.execution',
            'event_type' => 'child_run.launch_aborted',
            'exception_class' => $cause::class,
            'abort_phase' => $phase->value,
        ]);

        if (!$this->artifactLifecycle->hasRegistryEntry($parentRunId, $identity->artifactId)) {
            return;
        }

        $status = $this->artifactLifecycle->getArtifactStatus($parentRunId, $identity->artifactId);
        if (null !== $status && \in_array($status, [
            AgentArtifactStatusEnum::Completed,
            AgentArtifactStatusEnum::Failed,
            AgentArtifactStatusEnum::Cancelled,
            AgentArtifactStatusEnum::NeedsClarification,
        ], true)) {
            return;
        }

        if (AgentArtifactStatusEnum::Running === $status) {
            $this->agentRunner->cancel($identity->childRunId, $policy->launchAbortCancelReason);
        }

        $this->lifecycleListener->finalizeTerminalOutcome(ChildRunTerminalFinalizationRequestDTO::persistOnly(new ChildRunTerminalOutcomeDTO(
            $identity,
            AgentArtifactStatusEnum::Failed,
            failureReason: $cause->getMessage(),
            summary: 'Child run failed to start.',
        )));
    }
}
