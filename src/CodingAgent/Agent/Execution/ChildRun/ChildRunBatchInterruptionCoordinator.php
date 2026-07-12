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
                $update = $this->progressCoordinator->buildProgressUpdate($batch, $snapshots, [$only->childRunId => $state->turnNo], $progressSeq, $progressStartedMicros, 'cancelled', $only, $state, 'cancelled');
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
        if ($batch->isSingle()) {
            $only = $batch->children[0]->identity;
            $this->processPort->cancel($only->childRunId, 'Subagent timed out.');
            $timeoutState = $this->processPort->getState($only->childRunId);
            if (null !== $timeoutState) {
                $update = $this->progressCoordinator->buildProgressUpdate($batch, $snapshots, [$only->childRunId => $timeoutState->turnNo], $progressSeq, $progressStartedMicros, 'failed', $only, $timeoutState, 'failed');
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
}
