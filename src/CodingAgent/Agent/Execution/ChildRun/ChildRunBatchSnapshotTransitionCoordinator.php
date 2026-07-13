<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunArtifactLifecyclePort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunTerminalizerPort;

/**
 * Typed artifact/run-status transitions while a batch child is still active (single vs parallel paths).
 */
final class ChildRunBatchSnapshotTransitionCoordinator
{
    public function __construct(
        private readonly ChildRunArtifactLifecyclePort $artifactLifecycle,
        private readonly ChildRunTerminalizerPort $terminalizer,
    ) {
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     */
    public function hasActiveChildren(array $snapshots): bool
    {
        foreach ($snapshots as $snapshot) {
            if (!$snapshot->terminal) {
                return true;
            }
        }

        return false;
    }

    public function isRunTerminal(RunStatus $status): bool
    {
        return \in_array($status, [RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::Cancelling], true);
    }

    public function applySingleActiveTransition(ChildRunBatchItemSnapshotDTO $snapshot, RunState $state): void
    {
        $identity = $snapshot->identity;
        if (RunStatus::WaitingHuman === $state->status) {
            $this->artifactLifecycle->markNeedsClarification($identity);
            $snapshot->artifactStatus = AgentArtifactStatusEnum::NeedsClarification;

            return;
        }

        if (AgentArtifactStatusEnum::NeedsClarification === $snapshot->artifactStatus) {
            $this->artifactLifecycle->clearNeedsClarificationToRunning($identity);
            $snapshot->artifactStatus = AgentArtifactStatusEnum::Running;
        }
    }

    public function applyBatchItemTransition(ChildRunBatchItemSnapshotDTO $snapshot, RunState $state, bool $forParallel): void
    {
        if ($snapshot->terminal || !$forParallel) {
            return;
        }

        $identity = $snapshot->identity;
        $status = $state->status;

        if (RunStatus::Running === $status || RunStatus::Queued === $status || RunStatus::Compacting === $status) {
            if (AgentArtifactStatusEnum::NeedsClarification === $snapshot->artifactStatus) {
                $this->artifactLifecycle->clearNeedsClarificationToRunning($identity);
                $snapshot->artifactStatus = AgentArtifactStatusEnum::Running;
            }

            return;
        }

        if (RunStatus::WaitingHuman === $status) {
            $this->artifactLifecycle->markNeedsClarification($identity);
            $snapshot->artifactStatus = AgentArtifactStatusEnum::NeedsClarification;
            $snapshot->message = 'Waiting for human input.';

            return;
        }

        if (RunStatus::Completed === $status) {
            $summary = $this->terminalizer->summarizeCompletedSummary($state);
            $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO($identity, AgentArtifactStatusEnum::Completed, summary: $summary));
            $snapshot->markTerminalFromArtifactStatus(AgentArtifactStatusEnum::Completed, $summary);

            return;
        }

        if (RunStatus::Failed === $status) {
            $errorMsg = $state->errorMessage ?? 'Run failed without error message.';
            $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO($identity, AgentArtifactStatusEnum::Failed, failureReason: $errorMsg, summary: $errorMsg));
            $snapshot->markTerminalFailed($errorMsg);

            return;
        }

        if (\in_array($status, [RunStatus::Cancelled, RunStatus::Cancelling], true)) {
            $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO($identity, AgentArtifactStatusEnum::Cancelled, summary: 'Child run was cancelled.', childState: $state));
            $snapshot->markTerminalCancelled('Child run was cancelled.');
        }
    }
}
