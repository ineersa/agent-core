<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunArtifactLifecyclePort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunProcessPort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunTerminalizerPort;
use Psr\Log\LoggerInterface;

/**
 * Launch sequencing, pending artifact reservation, and structured abort when a batch launch fails mid-flight.
 */
final class ChildRunBatchLaunchCoordinator
{
    public function __construct(
        private readonly ChildRunProcessPort $processPort,
        private readonly ChildRunArtifactLifecyclePort $artifactLifecycle,
        private readonly ChildRunTerminalizerPort $terminalizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, ChildRunBatchItemSnapshotDTO>
     */
    public function initialSnapshots(ChildRunBatchDTO $batch): array
    {
        $snapshots = [];
        foreach ($batch->children as $prepared) {
            $id = $prepared->identity;
            $snapshots[$id->childRunId] = new ChildRunBatchItemSnapshotDTO($id, false, null, '');
        }

        return $snapshots;
    }

    public function launchAll(ChildRunBatchDTO $batch): void
    {
        foreach ($batch->children as $prepared) {
            if (!$prepared->artifactReservedPending) {
                $this->artifactLifecycle->reservePending($prepared);
            }

            $this->processPort->start($prepared->startRunInput);
            $this->artifactLifecycle->markRunning($prepared->identity);
        }
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     */
    public function abortLaunch(ChildRunBatchDTO $batch, array &$snapshots, \Throwable $cause): void
    {
        $parentRunId = $batch->parentRunId;
        $this->logger->warning('subagent_execution.parallel_launch_aborted', [
            'run_id' => $parentRunId,
            'component' => 'agent.execution',
            'event_type' => 'subagent_execution.parallel_launch_aborted',
            'task_count' => \count($snapshots),
            'exception_class' => $cause::class,
        ]);

        $neverLaunchedMessage = 'Child run was not launched after a parallel launch failure.';
        $lastRunningChildIndex = -1;
        foreach ($batch->children as $index => $prepared) {
            $status = $this->artifactLifecycle->getArtifactStatus($parentRunId, $prepared->identity->artifactId);
            if (AgentArtifactStatusEnum::Running === $status) {
                $lastRunningChildIndex = $index;
            }
        }

        foreach ($batch->children as $index => $prepared) {
            $childRunId = $prepared->identity->childRunId;
            if (!isset($snapshots[$childRunId])) {
                continue;
            }
            $snapshot = &$snapshots[$childRunId];
            if ($snapshot->terminal) {
                continue;
            }

            $identity = $snapshot->identity;
            if (!$this->artifactLifecycle->hasRegistryEntry($parentRunId, $identity->artifactId)) {
                $snapshot->terminal = true;
                $snapshot->artifactStatus = AgentArtifactStatusEnum::Failed;
                $snapshot->message = $neverLaunchedMessage;

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
                $snapshot->terminal = true;
                $snapshot->artifactStatus = $status;
                $snapshot->message = $status->value;

                continue;
            }

            if (AgentArtifactStatusEnum::Running === $status) {
                $this->processPort->cancel($childRunId, 'Parallel subagent launch aborted after sibling failure.');
                $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                    $identity,
                    AgentArtifactStatusEnum::Failed,
                    failureReason: $cause->getMessage(),
                    summary: 'Cancelled after parallel launch failure.',
                ));
                $snapshot->terminal = true;
                $snapshot->artifactStatus = AgentArtifactStatusEnum::Failed;
                $snapshot->message = 'Cancelled after parallel launch failure.';
            } else {
                if ($index > $lastRunningChildIndex + 1) {
                    $this->artifactLifecycle->removePendingRegistryEntry($identity);
                    $snapshot->terminal = true;
                    $snapshot->artifactStatus = AgentArtifactStatusEnum::Failed;
                    $snapshot->message = $neverLaunchedMessage;
                } else {
                    $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                        $identity,
                        AgentArtifactStatusEnum::Failed,
                        failureReason: $cause->getMessage(),
                        summary: 'Child run failed to start.',
                    ));
                    $snapshot->terminal = true;
                    $snapshot->artifactStatus = AgentArtifactStatusEnum::Failed;
                    $snapshot->message = 'Child run failed to start.';
                }
            }
        }
    }
}
