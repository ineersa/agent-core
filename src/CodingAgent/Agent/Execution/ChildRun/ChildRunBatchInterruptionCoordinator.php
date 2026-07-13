<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunProcessPort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunTerminalizerPort;

/**
 * Parent cancellation and batch timeout handling for foreground child-run batches.
 */
final class ChildRunBatchInterruptionCoordinator
{
    public function __construct(
        private readonly ChildRunProcessPort $processPort,
        private readonly ChildRunTerminalizerPort $terminalizer,
        private readonly ChildRunBatchProgressCoordinator $progressCoordinator,
    ) {
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     */
    public function handleParentCancellation(
        ChildRunBatchDTO $batch,
        array $snapshots,
        int $progressSeq,
        int $progressStartedMicros,
    ): ChildRunBatchSupervisionResultDTO {
        $policy = $batch->lifecyclePolicy;
        foreach ($snapshots as $childRunId => $snapshot) {
            if ($snapshot->terminal) {
                continue;
            }

            $reason = $batch->isSingle() ? $policy->parentCancelSingleReason : $policy->parentCancelParallelReason;
            $this->processPort->cancel($childRunId, $reason);
            $cancelState = $this->processPort->getState($childRunId);
            $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                $snapshot->identity,
                AgentArtifactStatusEnum::Cancelled,
                summary: 'Cancelled by parent run.',
                childState: $cancelState,
            ));
            $snapshot->markTerminalCancelled('Cancelled by parent run.');
        }

        if ($batch->isSingle()) {
            $only = $batch->children[0]->identity;
            $state = $this->processPort->getState($only->childRunId);
            if (null !== $state) {
                $update = $this->progressCoordinator->buildProgressUpdate(
                    $batch,
                    $snapshots,
                    [$only->childRunId => $state->turnNo],
                    $progressSeq,
                    $progressStartedMicros,
                    'cancelled',
                    new ChildRunSingleProgressContextDTO($only, $state, 'cancelled'),
                );
                $this->progressCoordinator->emitAndAdvance($batch->parentRunId, $update, $progressSeq);
            }
        } else {
            $this->progressCoordinator->emitAggregateProgress($batch, $snapshots, $progressSeq, $progressStartedMicros, 'cancelled');
        }

        return new ChildRunBatchSupervisionResultDTO($batch->parentRunId, array_values($snapshots), ChildRunBatchCompletionKindEnum::ParentCancelled);
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     */
    public function handleBatchTimeout(
        ChildRunBatchDTO $batch,
        array $snapshots,
        int $progressSeq,
        int $progressStartedMicros,
    ): ChildRunBatchSupervisionResultDTO {
        $policy = $batch->lifecyclePolicy;
        if ($batch->isSingle()) {
            $only = $batch->children[0]->identity;
            $this->processPort->cancel($only->childRunId, $policy->singleTimeoutCancelReason);
            $timeoutState = $this->processPort->getState($only->childRunId);
            if (null !== $timeoutState) {
                $update = $this->progressCoordinator->buildProgressUpdate(
                    $batch,
                    $snapshots,
                    [$only->childRunId => $timeoutState->turnNo],
                    $progressSeq,
                    $progressStartedMicros,
                    'failed',
                    new ChildRunSingleProgressContextDTO($only, $timeoutState, 'failed'),
                );
                $this->progressCoordinator->emitAndAdvance($batch->parentRunId, $update, $progressSeq);
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

            $this->processPort->cancel($childRunId, $policy->parallelTimeoutCancelReason);
            $this->terminalizer->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                $snapshot->identity,
                AgentArtifactStatusEnum::Failed,
                failureReason: 'Child run timed out.',
                summary: 'Timed out after '.$batch->timeoutSeconds.'s.',
            ));
            $snapshot->markTerminalFailed('Timed out after '.$batch->timeoutSeconds.'s.');
        }

        return new ChildRunBatchSupervisionResultDTO($batch->parentRunId, array_values($snapshots), ChildRunBatchCompletionKindEnum::BatchTimedOut);
    }
}
