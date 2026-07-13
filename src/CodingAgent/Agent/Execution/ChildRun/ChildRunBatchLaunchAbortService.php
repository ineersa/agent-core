<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunArtifactLifecyclePort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunProcessPort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunTerminalizerPort;
use Psr\Log\LoggerInterface;

/**
 * Canonical launch-abort state machine for a foreground child batch (preparation failure or runtime start failure).
 */
final class ChildRunBatchLaunchAbortService
{
    public function __construct(
        private readonly ChildRunArtifactLifecyclePort $artifactLifecycle,
        private readonly ChildRunProcessPort $processPort,
        private readonly ChildRunTerminalizerPort $terminalizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<ChildRunIdentityDTO> $identities
     */
    public function abort(
        string $parentRunId,
        array $identities,
        ChildRunBatchLifecyclePolicyDTO $policy,
        \Throwable $cause,
        ChildRunBatchLaunchAbortContextDTO $abortContext,
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
                    $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                        $identity,
                        AgentArtifactStatusEnum::Failed,
                        failureReason: $cause->getMessage(),
                        summary: $cancelledAfterMessage,
                    ));
                    $snapshot->markTerminalFailed($cancelledAfterMessage);

                    continue;
                }

                if ($identity->batchIndex === $failureIndex) {
                    if (!$this->artifactLifecycle->hasRegistryEntry($parentRunId, $identity->artifactId)) {
                        $snapshot->markTerminalFailed($neverLaunchedMessage);

                        continue;
                    }

                    $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                        $identity,
                        AgentArtifactStatusEnum::Failed,
                        failureReason: $cause->getMessage(),
                        summary: $failedToStartMessage,
                    ));
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

        $lastRunningChildIndex = -1;
        foreach ($identities as $index => $identity) {
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

            if (!$this->artifactLifecycle->hasRegistryEntry($parentRunId, $identity->artifactId)) {
                $snapshot->markTerminalFailed($neverLaunchedMessage);

                continue;
            }

            $status = $this->artifactLifecycle->getArtifactStatus($parentRunId, $identity->artifactId);
            if (null === $status) {
                continue;
            }

            if (\in_array($status, [
                AgentArtifactStatusEnum::Completed,
                AgentArtifactStatusEnum::Failed,
                AgentArtifactStatusEnum::Cancelled,
                AgentArtifactStatusEnum::NeedsClarification,
            ], true)) {
                $snapshot->markTerminalFromArtifactStatus($status, $status->value);

                continue;
            }

            if (AgentArtifactStatusEnum::Running === $status) {
                $this->processPort->cancel($childRunId, $policy->launchAbortSiblingCancelReason);
                $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                    $identity,
                    AgentArtifactStatusEnum::Failed,
                    failureReason: $cause->getMessage(),
                    summary: $cancelledAfterMessage,
                ));
                $snapshot->markTerminalFailed($cancelledAfterMessage);

                continue;
            }

            if ($index > $lastRunningChildIndex + 1) {
                $this->artifactLifecycle->removePendingReservation($identity);
                $snapshot->markTerminalFailed($neverLaunchedMessage);
            } else {
                $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                    $identity,
                    AgentArtifactStatusEnum::Failed,
                    failureReason: $cause->getMessage(),
                    summary: $failedToStartMessage,
                ));
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
