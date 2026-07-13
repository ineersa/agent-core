<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchCompletionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLaunchAbortContextDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchSupervisionResultDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunSingleProgressContextDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationRequestDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

/**
 * One shared typed batch lifecycle for foreground child runs (single child = batch of one).
 */
final class ForegroundChildRunSupervisor
{
    private const int DEFAULT_POLL_MICROS = 250_000;

    public function __construct(
        private readonly ChildRunBatchLifecycleListenerInterface $lifecycleListener,
        private readonly RunStoreInterface $childRunStore,
        private readonly ChildRunBatchLaunchService $launchService,
        private readonly ChildRunBatchSnapshotTransitionService $transitionService,
        private readonly ChildRunBatchProgressService $progressService,
        private readonly ChildRunBatchInterruptionService $interruptionService,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function supervise(ChildRunBatchDTO $batch): ChildRunBatchSupervisionResultDTO
    {
        $parentRunId = $batch->parentRunId;
        $snapshots = $this->launchService->initialSnapshots($batch);

        try {
            $this->launchService->launchAll($batch);
        } catch (\Throwable $e) {
            return $this->launchService->abort(
                $parentRunId,
                array_map(static fn (PreparedAgentChildRunDTO $p): ChildRunIdentityDTO => $p->identity, $batch->children),
                $batch->lifecyclePolicy,
                $e,
                ChildRunBatchLaunchAbortContextDTO::runtimeStart(),
            );
        }

        $progressStartedMicros = $this->nowMicros();
        $deadline = $progressStartedMicros + $batch->timeoutSeconds * 1_000_000;
        $cancelToken = $this->contextAccessor->current()?->cancellationToken();
        $lastSignature = null;

        while ($this->transitionService->hasActiveChildren($snapshots)) {
            if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                return $this->interruptionService->handleParentCancellation($batch, $snapshots, $progressStartedMicros);
            }

            if ($this->nowMicros() > $deadline) {
                return $this->interruptionService->handleBatchTimeout($batch, $snapshots, $progressStartedMicros);
            }

            $activeTurns = [];
            foreach ($snapshots as $childRunId => $snapshot) {
                if ($snapshot->terminal) {
                    continue;
                }

                $state = $this->childRunStore->get($childRunId);
                if (null === $state) {
                    continue;
                }

                $activeTurns[$childRunId] = $state->turnNo;

                if ($batch->isSingle()) {
                    if ($this->transitionService->isRunTerminal($state->status)) {
                        return $this->finishSingleFromTerminalState($batch, $snapshot, $state, $progressStartedMicros, $snapshots);
                    }

                    $this->transitionService->applySingleActiveTransition($snapshot, $state);
                    $progressStatus = RunStatus::WaitingHuman === $state->status ? 'waiting_human' : 'running';
                    $update = $this->progressService->buildProgressUpdate(
                        $batch,
                        $snapshots,
                        $activeTurns,
                        $progressStartedMicros,
                        $progressStatus,
                        new ChildRunSingleProgressContextDTO($snapshot->identity, $state, $progressStatus),
                    );
                    $this->progressService->emitDedupedIfChanged($update, $lastSignature);

                    continue;
                }

                $this->transitionService->applyBatchItemTransition($snapshot, $state, true);
            }

            if (!$batch->isSingle()) {
                $update = $this->progressService->buildProgressUpdate($batch, $snapshots, $activeTurns, $progressStartedMicros, 'running');
                $this->progressService->emitDedupedIfChanged($update, $lastSignature);
            }
            $this->sleepPollInterval();
        }

        $this->progressService->emitAggregateProgress($batch, $snapshots, $progressStartedMicros, $this->progressService->resolveAggregateStatus($snapshots));

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
        int $progressStartedMicros,
        array $snapshots,
    ): ChildRunBatchSupervisionResultDTO {
        $identity = $snapshot->identity;
        $terminalStatus = $this->progressService->mapTerminalProgressStatus($state);

        $update = $this->progressService->buildProgressUpdate(
            $batch,
            $snapshots,
            [$identity->childRunId => $state->turnNo],
            $progressStartedMicros,
            $terminalStatus,
            new ChildRunSingleProgressContextDTO($identity, $state, $terminalStatus),
        );
        $this->progressService->emitProgress($update);

        $finalizationRequest = match ($state->status) {
            RunStatus::Completed => ChildRunTerminalFinalizationRequestDTO::singleCompleted($identity, $state),
            RunStatus::Failed => ChildRunTerminalFinalizationRequestDTO::singleFailed($identity, $state),
            RunStatus::Cancelled, RunStatus::Cancelling => ChildRunTerminalFinalizationRequestDTO::singleChildCancelled($identity, $state),
            default => throw new ToolCallException('Unexpected terminal child status: '.$state->status->value, retryable: false),
        };
        $result = $this->lifecycleListener->finalizeTerminalOutcome($finalizationRequest)->presentationMessage;

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
