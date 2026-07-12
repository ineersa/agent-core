<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchCompletionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchSupervisionResultDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunArtifactLifecyclePort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunProcessPort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunTerminalizerPort;
use Psr\Log\LoggerInterface;

final class SubagentParallelLaunchFailureFinalizer
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
    public function finalize(string $parentRunId, array $identities, \Throwable $cause): ChildRunBatchSupervisionResultDTO
    {
        $this->logger->warning('subagent_execution.parallel_launch_aborted', [
            'run_id' => $parentRunId,
            'component' => 'agent.execution',
            'event_type' => 'subagent_execution.parallel_launch_aborted',
            'task_count' => \count($identities),
            'exception_class' => $cause::class,
        ]);

        $items = [];
        $neverLaunchedMessage = 'Child run was not launched after a parallel launch failure.';

        foreach ($identities as $identity) {
            $snapshot = new ChildRunBatchItemSnapshotDTO($identity, false, null, '');
            if (!$this->artifactLifecycle->hasRegistryEntry($parentRunId, $identity->artifactId)) {
                $snapshot->terminal = true;
                $snapshot->artifactStatus = AgentArtifactStatusEnum::Failed;
                $snapshot->message = $neverLaunchedMessage;
                $items[] = $snapshot;

                continue;
            }

            $status = $this->artifactLifecycle->getArtifactStatus($parentRunId, $identity->artifactId);
            if (null === $status) {
                $items[] = $snapshot;

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
                $items[] = $snapshot;

                continue;
            }

            if (AgentArtifactStatusEnum::Running === $status) {
                $this->processPort->cancel($identity->childRunId, 'Parallel subagent launch aborted after sibling failure.');
                $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                    $identity,
                    AgentArtifactStatusEnum::Failed,
                    failureReason: $cause->getMessage(),
                    summary: 'Cancelled after parallel launch failure.',
                ));
                $snapshot->terminal = true;
                $snapshot->artifactStatus = AgentArtifactStatusEnum::Failed;
                $snapshot->message = 'Cancelled after parallel launch failure.';
                $items[] = $snapshot;

                continue;
            }

            $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                $identity,
                AgentArtifactStatusEnum::Failed,
                failureReason: $cause->getMessage(),
                summary: 'Child run failed to start.',
            ));
            $snapshot->terminal = true;
            $snapshot->artifactStatus = AgentArtifactStatusEnum::Failed;
            $snapshot->message = 'Child run failed to start.';
            $items[] = $snapshot;
        }

        return new ChildRunBatchSupervisionResultDTO($parentRunId, $items, ChildRunBatchCompletionKindEnum::LaunchAborted, launchFailure: $cause);
    }
}
