<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunArtifactLifecyclePort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunProcessPort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunProgressSinkPort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunTerminalizerPort;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

/**
 * One shared typed batch lifecycle for foreground child runs (single child = batch of one).
 *
 * Subagent-specific progress payloads and handoff wording stay behind progress/terminalizer ports.
 */
final class ForegroundAgentChildRunSupervisor
{
    private const int DEFAULT_POLL_MICROS = 250_000;

    public function __construct(
        private readonly ChildRunProcessPort $processPort,
        private readonly ChildRunArtifactLifecyclePort $artifactLifecycle,
        private readonly ChildRunProgressSinkPort $progressSink,
        private readonly ChildRunTerminalizerPort $terminalizer,
        private readonly AgentChildParentSequenceCoordinator $sequenceCoordinator,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function supervise(ChildRunBatchDTO $batch): ChildRunBatchSupervisionResultDTO
    {
        $parentRunId = $batch->parentRunId;
        $snapshots = $this->initialSnapshots($batch);

        try {
            $this->launchAll($batch);
        } catch (\Throwable $e) {
            $this->abortLaunch($parentRunId, $snapshots, $e);

            return new ChildRunBatchSupervisionResultDTO($parentRunId, array_values($snapshots), ChildRunBatchCompletionKindEnum::LaunchAborted, launchFailure: $e);
        }

        $progressStartedMicros = $this->nowMicros();
        $deadline = $progressStartedMicros + $batch->timeoutSeconds * 1_000_000;
        $cancelToken = $this->contextAccessor->current()?->cancellationToken();
        $progressSeq = $this->sequenceCoordinator->resolveNextProgressSeq($parentRunId);
        $lastSignature = null;

        while ($this->hasActiveChildren($snapshots)) {
            if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                return $this->handleParentCancellation($batch, $snapshots, $progressSeq, $progressStartedMicros);
            }

            if ($this->nowMicros() > $deadline) {
                return $this->handleBatchTimeout($batch, $snapshots, $progressSeq, $progressStartedMicros);
            }

            $activeTurns = [];
            foreach ($snapshots as $childRunId => $snapshot) {
                if ($snapshot->terminal) {
                    continue;
                }

                $state = $this->processPort->getState($childRunId);
                if (null === $state) {
                    continue;
                }

                $activeTurns[$childRunId] = $state->turnNo;

                if ($batch->isSingle()) {
                    if ($this->isRunTerminal($state->status)) {
                        return $this->finishSingleFromTerminalState($batch, $snapshot, $state, $progressSeq, $progressStartedMicros, $snapshots);
                    }

                    $this->applySingleActiveTransition($snapshot, $state);
                    $update = $this->buildProgressUpdate(
                        $batch,
                        $snapshots,
                        $activeTurns,
                        $progressSeq,
                        $progressStartedMicros,
                        RunStatus::WaitingHuman === $state->status ? 'waiting_human' : 'running',
                        $snapshot->identity,
                        $state,
                        RunStatus::WaitingHuman === $state->status ? 'waiting_human' : 'running',
                    );
                    $signature = $this->progressSink->progressSignature($update);
                    if (null === $lastSignature || $signature !== $lastSignature) {
                        $this->progressSink->emit($update);
                        $this->sequenceCoordinator->advanceParentSequence($batch->parentRunId, $progressSeq);
                        ++$progressSeq;
                        $lastSignature = $signature;
                    }

                    continue;
                }

                $this->applyBatchItemTransition($snapshot, $state, true);
            }

            if (!$batch->isSingle()) {
                $update = $this->buildProgressUpdate($batch, $snapshots, $activeTurns, $progressSeq, $progressStartedMicros, 'running');
                $signature = $this->progressSink->progressSignature($update);
                if (null === $lastSignature || $signature !== $lastSignature) {
                    $this->progressSink->emit($update);
                    $this->sequenceCoordinator->advanceParentSequence($parentRunId, $progressSeq);
                    ++$progressSeq;
                    $lastSignature = $signature;
                }
            }
            $this->sleepPollInterval();
        }

        $this->emitAggregateProgress($batch, $snapshots, $progressSeq, $progressStartedMicros, $this->resolveAggregateStatus($snapshots));

        if ($batch->isSingle()) {
            throw new ToolCallException('Single child supervision ended without terminal state.', retryable: false);
        }

        $failed = array_filter($snapshots, static fn (ChildRunBatchItemSnapshotDTO $s): bool => AgentArtifactStatusEnum::Completed !== $s->artifactStatus);

        return new ChildRunBatchSupervisionResultDTO(
            $parentRunId,
            array_values($snapshots),
            [] === $failed ? ChildRunBatchCompletionKindEnum::AllSucceeded : ChildRunBatchCompletionKindEnum::PartialFailure,
        );
    }

    /**
     * @return array<string, ChildRunBatchItemSnapshotDTO>
     */
    private function initialSnapshots(ChildRunBatchDTO $batch): array
    {
        $snapshots = [];
        foreach ($batch->children as $prepared) {
            $id = $prepared->identity;
            $snapshots[$id->childRunId] = new ChildRunBatchItemSnapshotDTO($id, false, null, '');
        }

        return $snapshots;
    }

    private function launchAll(ChildRunBatchDTO $batch): void
    {
        foreach ($batch->children as $prepared) {
            if (!$prepared->artifactReservedPending) {
                $this->artifactLifecycle->reservePending($prepared);
            }

            $this->processPort->start($prepared->startRunInput);
            $this->artifactLifecycle->markRunning($prepared->identity);
        }
    }



    private function applySingleActiveTransition(ChildRunBatchItemSnapshotDTO $snapshot, RunState $state): void
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

    private function finishSingleFromTerminalState(
        ChildRunBatchDTO $batch,
        ChildRunBatchItemSnapshotDTO $snapshot,
        RunState $state,
        int $progressSeq,
        int $progressStartedMicros,
        array $snapshots,
    ): ChildRunBatchSupervisionResultDTO {
        $identity = $snapshot->identity;
        $terminalStatus = $this->progressSink->mapTerminalProgressStatus($state);

        $update = $this->buildProgressUpdate(
            $batch,
            $snapshots,
            [$identity->childRunId => $state->turnNo],
            $progressSeq,
            $progressStartedMicros,
            $terminalStatus,
            $identity,
            $state,
            $terminalStatus,
        );
        $this->progressSink->emit($update);
        $this->sequenceCoordinator->advanceParentSequence($batch->parentRunId, $progressSeq);

        $result = match ($state->status) {
            RunStatus::Completed => $this->terminalizer->completeToolResult($identity, $state),
            RunStatus::Failed => $this->terminalizer->failToolResult($identity, $state),
            RunStatus::Cancelled, RunStatus::Cancelling => $this->terminalizer->cancelChildToolResult($identity, $state),
            default => throw new ToolCallException('Unexpected terminal child status: '.$state->status->value, retryable: false),
        };

        $snapshot->terminal = true;
        $snapshot->artifactStatus = match ($state->status) {
            RunStatus::Completed => AgentArtifactStatusEnum::Completed,
            RunStatus::Failed => AgentArtifactStatusEnum::Failed,
            default => AgentArtifactStatusEnum::Cancelled,
        };
        $snapshot->message = $result;

        return new ChildRunBatchSupervisionResultDTO(
            $batch->parentRunId,
            array_values($snapshots),
            ChildRunBatchCompletionKindEnum::SingleSucceeded,
            $result,
        );
    }

    private function applyBatchItemTransition(ChildRunBatchItemSnapshotDTO $snapshot, RunState $state, bool $forParallel): void
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
            $snapshot->terminal = true;
            $snapshot->artifactStatus = AgentArtifactStatusEnum::Completed;
            $snapshot->message = $summary;

            return;
        }

        if (RunStatus::Failed === $status) {
            $errorMsg = $state->errorMessage ?? 'Run failed without error message.';
            $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO($identity, AgentArtifactStatusEnum::Failed, failureReason: $errorMsg, summary: $errorMsg));
            $snapshot->terminal = true;
            $snapshot->artifactStatus = AgentArtifactStatusEnum::Failed;
            $snapshot->message = $errorMsg;

            return;
        }

        if (RunStatus::Cancelled === $status || RunStatus::Cancelling === $status) {
            $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO($identity, AgentArtifactStatusEnum::Cancelled, summary: 'Child run was cancelled.', childState: $state));
            $snapshot->terminal = true;
            $snapshot->artifactStatus = AgentArtifactStatusEnum::Cancelled;
            $snapshot->message = 'Child run was cancelled.';
        }
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     */
    private function handleParentCancellation(
        ChildRunBatchDTO $batch,
        array $snapshots,
        int $progressSeq,
        int $progressStartedMicros,
    ): ChildRunBatchSupervisionResultDTO {
        foreach ($snapshots as $childRunId => $snapshot) {
            if ($snapshot->terminal) {
                continue;
            }

            $this->processPort->cancel($childRunId, $batch->isSingle() ? 'Parent run cancelled subagent tool.' : 'Parent run cancelled parallel subagent tool.');
            $cancelState = $this->processPort->getState($childRunId);
            $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                $snapshot->identity,
                AgentArtifactStatusEnum::Cancelled,
                summary: 'Cancelled by parent run.',
                childState: $cancelState,
            ));
            $snapshot->terminal = true;
            $snapshot->artifactStatus = AgentArtifactStatusEnum::Cancelled;
            $snapshot->message = 'Cancelled by parent run.';
        }

        if ($batch->isSingle()) {
            $only = $batch->children[0]->identity;
            $state = $this->processPort->getState($only->childRunId);
            if (null !== $state) {
                $update = $this->buildProgressUpdate($batch, $snapshots, [$only->childRunId => $state->turnNo], $progressSeq, $progressStartedMicros, 'cancelled', $only, $state, 'cancelled');
                $this->progressSink->emit($update);
                $this->sequenceCoordinator->advanceParentSequence($batch->parentRunId, $progressSeq);
            }
        } else {
            $this->emitAggregateProgress($batch, $snapshots, $progressSeq, $progressStartedMicros, 'cancelled');
        }

        return new ChildRunBatchSupervisionResultDTO($batch->parentRunId, array_values($snapshots), ChildRunBatchCompletionKindEnum::ParentCancelled);
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     */
    private function handleBatchTimeout(
        ChildRunBatchDTO $batch,
        array $snapshots,
        int $progressSeq,
        int $progressStartedMicros,
    ): ChildRunBatchSupervisionResultDTO {
        if ($batch->isSingle()) {
            $only = $batch->children[0]->identity;
            $snapshot = $snapshots[$only->childRunId];
            $this->processPort->cancel($only->childRunId, 'Subagent timed out.');
            $timeoutState = $this->processPort->getState($only->childRunId);
            if (null !== $timeoutState) {
                $update = $this->buildProgressUpdate($batch, $snapshots, [$only->childRunId => $timeoutState->turnNo], $progressSeq, $progressStartedMicros, 'failed', $only, $timeoutState, 'failed');
                $this->progressSink->emit($update);
                $this->sequenceCoordinator->advanceParentSequence($batch->parentRunId, $progressSeq);
            }

            $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                $only,
                AgentArtifactStatusEnum::Failed,
                failureReason: 'Child run timed out.',
                summary: 'Timed out after '.$batch->timeoutSeconds.'s.',
            ));
            $result = $this->terminalizer->timeoutToolResult($only, $batch->timeoutSeconds);

            return new ChildRunBatchSupervisionResultDTO($batch->parentRunId, array_values($snapshots), ChildRunBatchCompletionKindEnum::SingleTimedOut, $result);
        }

        foreach ($snapshots as $childRunId => $snapshot) {
            if ($snapshot->terminal) {
                continue;
            }

            $this->processPort->cancel($childRunId, 'Parallel subagent timed out.');
            $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                $snapshot->identity,
                AgentArtifactStatusEnum::Failed,
                failureReason: 'Child run timed out.',
                summary: 'Timed out after '.$batch->timeoutSeconds.'s.',
            ));
            $snapshot->terminal = true;
            $snapshot->artifactStatus = AgentArtifactStatusEnum::Failed;
            $snapshot->message = 'Timed out after '.$batch->timeoutSeconds.'s.';
        }

        return new ChildRunBatchSupervisionResultDTO($batch->parentRunId, array_values($snapshots), ChildRunBatchCompletionKindEnum::BatchTimedOut);
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     */
    private function abortLaunch(string $parentRunId, array &$snapshots, \Throwable $cause): void
    {
        $this->logger->warning('subagent_execution.parallel_launch_aborted', [
            'run_id' => $parentRunId,
            'component' => 'agent.execution',
            'event_type' => 'subagent_execution.parallel_launch_aborted',
            'task_count' => \count($snapshots),
            'exception_class' => $cause::class,
        ]);

        $neverLaunchedMessage = 'Child run was not launched after a parallel launch failure.';

        foreach ($snapshots as $childRunId => $snapshot) {
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
        }
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     */
    private function hasActiveChildren(array $snapshots): bool
    {
        foreach ($snapshots as $snapshot) {
            if (!$snapshot->terminal) {
                return true;
            }
        }

        return false;
    }

    private function isRunTerminal(RunStatus $status): bool
    {
        return \in_array($status, [RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::Cancelling], true);
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     * @param array<string, int>                          $activeTurns
     */
    private function buildProgressUpdate(
        ChildRunBatchDTO $batch,
        array $snapshots,
        array $activeTurns,
        int $seq,
        int $progressStartedMicros,
        string $aggregateStatus,
        ?ChildRunIdentityDTO $singleIdentity = null,
        ?RunState $singleState = null,
        string $singleProgressStatus = 'running',
    ): ChildRunProgressUpdateDTO {
        if ($batch->isSingle() && null === $singleIdentity) {
            $only = $batch->children[0]->identity;
            $state = $this->processPort->getState($only->childRunId);
            $singleIdentity = $only;
            $singleState = $state;
        }

        return new ChildRunProgressUpdateDTO(
            parentRunId: $batch->parentRunId,
            items: array_values($snapshots),
            activeTurns: $activeTurns,
            seq: $seq,
            progressStartedMicros: $progressStartedMicros,
            aggregateStatus: $aggregateStatus,
            isSingleChild: $batch->isSingle(),
            singleIdentity: $singleIdentity,
            singleState: $singleState,
            singleProgressStatus: $singleProgressStatus,
        );
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     */
    private function emitAggregateProgress(ChildRunBatchDTO $batch, array $snapshots, int $progressSeq, int $progressStartedMicros, string $aggregateStatus): void
    {
        $update = $this->buildProgressUpdate($batch, $snapshots, $this->collectActiveTurns($snapshots), $progressSeq, $progressStartedMicros, $aggregateStatus);
        $this->progressSink->emit($update);
        $this->sequenceCoordinator->advanceParentSequence($batch->parentRunId, $progressSeq);
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     *
     * @return array<string, int>
     */
    private function collectActiveTurns(array $snapshots): array
    {
        $turns = [];
        foreach ($snapshots as $childRunId => $snapshot) {
            $state = $this->processPort->getState($childRunId);
            if (null !== $state) {
                $turns[$childRunId] = $state->turnNo;
            }
        }

        return $turns;
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     */
    private function resolveAggregateStatus(array $snapshots): string
    {
        $hasFailed = false;
        $hasCancelled = false;
        foreach ($snapshots as $snapshot) {
            if (!$snapshot->terminal || null === $snapshot->artifactStatus) {
                continue;
            }
            if (AgentArtifactStatusEnum::Failed === $snapshot->artifactStatus) {
                $hasFailed = true;
            }
            if (AgentArtifactStatusEnum::Cancelled === $snapshot->artifactStatus) {
                $hasCancelled = true;
            }
        }

        if ($hasFailed) {
            return 'failed';
        }

        if ($hasCancelled) {
            return 'cancelled';
        }

        return 'completed';
    }

    private function nowMicros(): int
    {
        $instant = $this->clock->now();

        return ((int) $instant->format('U')) * 1_000_000 + (int) $instant->format('u');
    }

    private function sleepPollInterval(): void
    {
        $this->clock->sleep(self::DEFAULT_POLL_MICROS / 1_000_000);
    }
}
