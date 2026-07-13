<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunProcessPort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunTerminalizerPort;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

/**
 * One shared typed batch lifecycle for foreground child runs (single child = batch of one).
 */
final class ForegroundAgentChildRunSupervisor
{
    private const int DEFAULT_POLL_MICROS = 250_000;

    public function __construct(
        private readonly ChildRunProcessPort $processPort,
        private readonly ChildRunTerminalizerPort $terminalizer,
        private readonly AgentChildParentSequenceCoordinator $sequenceCoordinator,
        private readonly ChildRunBatchLaunchCoordinator $launchCoordinator,
        private readonly ChildRunBatchLaunchAbortService $launchAbortService,
        private readonly ChildRunBatchSnapshotTransitionCoordinator $transitionCoordinator,
        private readonly ChildRunBatchProgressCoordinator $progressCoordinator,
        private readonly ChildRunBatchInterruptionCoordinator $interruptionCoordinator,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function supervise(ChildRunBatchDTO $batch): ChildRunBatchSupervisionResultDTO
    {
        $parentRunId = $batch->parentRunId;
        $snapshots = $this->launchCoordinator->initialSnapshots($batch);

        try {
            $this->launchCoordinator->launchAll($batch);
        } catch (\Throwable $e) {
            return $this->launchAbortService->abort(
                $parentRunId,
                array_map(static fn (PreparedAgentChildRunDTO $p): ChildRunIdentityDTO => $p->identity, $batch->children),
                $batch->lifecyclePolicy,
                $e,
            );
        }

        $progressStartedMicros = $this->nowMicros();
        $deadline = $progressStartedMicros + $batch->timeoutSeconds * 1_000_000;
        $cancelToken = $this->contextAccessor->current()?->cancellationToken();
        $progressSeq = $this->sequenceCoordinator->resolveNextProgressSeq($parentRunId);
        $lastSignature = null;

        while ($this->transitionCoordinator->hasActiveChildren($snapshots)) {
            if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                return $this->interruptionCoordinator->handleParentCancellation($batch, $snapshots, $progressSeq, $progressStartedMicros);
            }

            if ($this->nowMicros() > $deadline) {
                return $this->interruptionCoordinator->handleBatchTimeout($batch, $snapshots, $progressSeq, $progressStartedMicros);
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
                    if ($this->transitionCoordinator->isRunTerminal($state->status)) {
                        return $this->finishSingleFromTerminalState($batch, $snapshot, $state, $progressSeq, $progressStartedMicros, $snapshots);
                    }

                    $this->transitionCoordinator->applySingleActiveTransition($snapshot, $state);
                    $progressStatus = RunStatus::WaitingHuman === $state->status ? 'waiting_human' : 'running';
                    $update = $this->progressCoordinator->buildProgressUpdate(
                        $batch,
                        $snapshots,
                        $activeTurns,
                        $progressSeq,
                        $progressStartedMicros,
                        $progressStatus,
                        new ChildRunSingleProgressContextDTO($snapshot->identity, $state, $progressStatus),
                    );
                    $this->progressCoordinator->emitDedupedIfChanged($parentRunId, $update, $progressSeq, $lastSignature);

                    continue;
                }

                $this->transitionCoordinator->applyBatchItemTransition($snapshot, $state, true);
            }

            if (!$batch->isSingle()) {
                $update = $this->progressCoordinator->buildProgressUpdate($batch, $snapshots, $activeTurns, $progressSeq, $progressStartedMicros, 'running');
                $this->progressCoordinator->emitDedupedIfChanged($parentRunId, $update, $progressSeq, $lastSignature);
            }
            $this->sleepPollInterval();
        }

        $this->progressCoordinator->emitAggregateProgress($batch, $snapshots, $progressSeq, $progressStartedMicros, $this->progressCoordinator->resolveAggregateStatus($snapshots));

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
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     */
    private function finishSingleFromTerminalState(
        ChildRunBatchDTO $batch,
        ChildRunBatchItemSnapshotDTO $snapshot,
        RunState $state,
        int $progressSeq,
        int $progressStartedMicros,
        array $snapshots,
    ): ChildRunBatchSupervisionResultDTO {
        $identity = $snapshot->identity;
        $terminalStatus = $this->progressCoordinator->mapTerminalProgressStatus($state);

        $update = $this->progressCoordinator->buildProgressUpdate(
            $batch,
            $snapshots,
            [$identity->childRunId => $state->turnNo],
            $progressSeq,
            $progressStartedMicros,
            $terminalStatus,
            new ChildRunSingleProgressContextDTO($identity, $state, $terminalStatus),
        );
        $this->progressCoordinator->emitAndAdvance($batch->parentRunId, $update, $progressSeq);

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
