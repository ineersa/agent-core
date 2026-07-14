<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchCompletionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLaunchAbortContextDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLaunchAbortPhaseEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLifecyclePolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchSupervisionResultDTO;
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

    /**
     * @param list<ChildRunIdentityDTO> $identities
     * @param list<string>              $knownStartedChildRunIds child runs whose {@see AgentRunnerInterface::start()} returned successfully in this launch attempt (authoritative when artifact Running persistence degraded)
     */
    public function abort(
        string $parentRunId,
        array $identities,
        ChildRunBatchLifecyclePolicyDTO $policy,
        \Throwable $cause,
        ChildRunBatchLaunchAbortContextDTO $abortContext,
        array $knownStartedChildRunIds = [],
    ): ChildRunBatchSupervisionResultDTO {
        $this->logger->warning('child_run.batch_launch_aborted', [
            'run_id' => $parentRunId,
            'component' => 'agent.execution',
            'event_type' => 'child_run.batch_launch_aborted',
            'task_count' => \count($identities),
            'exception_class' => $cause::class,
            'abort_phase' => $abortContext->phase->value,
            'preparation_failure_batch_index' => $abortContext->preparationFailureBatchIndex,
        ]);

        $snapshots = [];
        foreach ($identities as $identity) {
            $snapshots[$identity->childRunId] = new ChildRunBatchItemSnapshotDTO($identity, false, null, '');
        }

        $neverLaunchedMessage = 'Child run was not launched after a parallel launch failure.';
        $cancelledAfterMessage = 'Cancelled after parallel launch failure.';
        $failedToStartMessage = 'Child run failed to start.';

        if (ChildRunBatchLaunchAbortPhaseEnum::Preparation === $abortContext->phase) {
            $failureIndex = $abortContext->preparationFailureBatchIndex
                ?? throw new \InvalidArgumentException('Preparation abort requires preparationFailureBatchIndex.');

            foreach ($identities as $identity) {
                $childRunId = $identity->childRunId;
                $snapshot = $snapshots[$childRunId] ?? null;
                if (null === $snapshot || $snapshot->terminal) {
                    continue;
                }

                if ($identity->batchIndex < $failureIndex) {
                    $this->lifecycleListener->finalizeTerminalOutcome(ChildRunTerminalFinalizationRequestDTO::persistOnly(new ChildRunTerminalOutcomeDTO(
                        $identity,
                        AgentArtifactStatusEnum::Failed,
                        failureReason: $cause->getMessage(),
                        summary: $cancelledAfterMessage,
                    )));
                    $snapshot->markTerminalFailed($cancelledAfterMessage);

                    continue;
                }

                if ($identity->batchIndex === $failureIndex) {
                    if (!$this->artifactLifecycle->hasRegistryEntry($parentRunId, $identity->artifactId)) {
                        $snapshot->markTerminalFailed($neverLaunchedMessage);

                        continue;
                    }

                    $this->lifecycleListener->finalizeTerminalOutcome(ChildRunTerminalFinalizationRequestDTO::persistOnly(new ChildRunTerminalOutcomeDTO(
                        $identity,
                        AgentArtifactStatusEnum::Failed,
                        failureReason: $cause->getMessage(),
                        summary: $failedToStartMessage,
                    )));
                    $snapshot->markTerminalFailed($failedToStartMessage);

                    continue;
                }

                if (!$this->artifactLifecycle->hasRegistryEntry($parentRunId, $identity->artifactId)) {
                    $snapshot->markTerminalFailed($neverLaunchedMessage);

                    continue;
                }

                $this->artifactLifecycle->removePendingReservation($identity);
                $snapshot->markTerminalFailed($neverLaunchedMessage);
            }

            return new ChildRunBatchSupervisionResultDTO(
                $parentRunId,
                array_values($snapshots),
                ChildRunBatchCompletionKindEnum::LaunchAborted,
                launchFailure: $cause,
            );
        }

        $knownStartedChildRunIds = array_values(array_unique($knownStartedChildRunIds));
        $knownStartedSet = array_fill_keys($knownStartedChildRunIds, true);

        $lastRunningChildIndex = -1;
        foreach ($identities as $index => $identity) {
            if (isset($knownStartedSet[$identity->childRunId])) {
                $lastRunningChildIndex = $index;

                continue;
            }

            $status = $this->artifactLifecycle->getArtifactStatus($parentRunId, $identity->artifactId);
            if (AgentArtifactStatusEnum::Running === $status) {
                $lastRunningChildIndex = $index;
            }
        }

        foreach ($identities as $index => $identity) {
            $childRunId = $identity->childRunId;
            $snapshot = $snapshots[$childRunId] ?? null;
            if (null === $snapshot || $snapshot->terminal) {
                continue;
            }

            $knownStarted = isset($knownStartedSet[$childRunId]);
            $hasRegistryEntry = $this->artifactLifecycle->hasRegistryEntry($parentRunId, $identity->artifactId);
            $status = $hasRegistryEntry
                ? $this->artifactLifecycle->getArtifactStatus($parentRunId, $identity->artifactId)
                : null;

            if (null !== $status && \in_array($status, [
                AgentArtifactStatusEnum::Completed,
                AgentArtifactStatusEnum::Failed,
                AgentArtifactStatusEnum::Cancelled,
                AgentArtifactStatusEnum::NeedsClarification,
            ], true)) {
                $snapshot->markTerminalFromArtifactStatus($status, $status->value);

                continue;
            }

            if ($knownStarted || AgentArtifactStatusEnum::Running === $status) {
                $this->agentRunner->cancel($childRunId, $policy->launchAbortSiblingCancelReason);
                if ($hasRegistryEntry) {
                    $this->lifecycleListener->finalizeTerminalOutcome(ChildRunTerminalFinalizationRequestDTO::persistOnly(new ChildRunTerminalOutcomeDTO(
                        $identity,
                        AgentArtifactStatusEnum::Failed,
                        failureReason: $cause->getMessage(),
                        summary: $cancelledAfterMessage,
                    )));
                }
                $snapshot->markTerminalFailed($cancelledAfterMessage);

                continue;
            }

            if (!$hasRegistryEntry) {
                $snapshot->markTerminalFailed($neverLaunchedMessage);

                continue;
            }

            if (null === $status) {
                continue;
            }

            if ($index > $lastRunningChildIndex + 1) {
                $this->artifactLifecycle->removePendingReservation($identity);
                $snapshot->markTerminalFailed($neverLaunchedMessage);
            } else {
                $this->lifecycleListener->finalizeTerminalOutcome(ChildRunTerminalFinalizationRequestDTO::persistOnly(new ChildRunTerminalOutcomeDTO(
                    $identity,
                    AgentArtifactStatusEnum::Failed,
                    failureReason: $cause->getMessage(),
                    summary: $failedToStartMessage,
                )));
                $snapshot->markTerminalFailed($failedToStartMessage);
            }
        }

        return new ChildRunBatchSupervisionResultDTO(
            $parentRunId,
            array_values($snapshots),
            ChildRunBatchCompletionKindEnum::LaunchAborted,
            launchFailure: $cause,
        );
    }
}
