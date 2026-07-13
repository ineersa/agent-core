<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchCompletionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchSupervisionResultDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunSingleProgressContextDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;

/**
 * Parent cancellation and batch timeout handling for foreground child-run batches.
 */
final class ChildRunBatchInterruptionService
{
    public function __construct(
        private readonly AgentRunnerInterface $agentRunner,
        private readonly RunStoreInterface $childRunStore,
        private readonly ChildRunBatchLifecycleListenerInterface $lifecycleListener,
        private readonly ChildRunBatchProgressService $progressService,
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
            $this->agentRunner->cancel($childRunId, $reason);
            $cancelState = $this->childRunStore->get($childRunId);
            $this->lifecycleListener->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                $snapshot->identity,
                AgentArtifactStatusEnum::Cancelled,
                summary: 'Cancelled by parent run.',
                childState: $cancelState,
            ));
            $snapshot->markTerminalCancelled('Cancelled by parent run.');
        }

        if ($batch->isSingle()) {
            $only = $batch->children[0]->identity;
            $state = $this->childRunStore->get($only->childRunId);
            if (null !== $state) {
                $update = $this->progressService->buildProgressUpdate(
                    $batch,
                    $snapshots,
                    [$only->childRunId => $state->turnNo],
                    $progressSeq,
                    $progressStartedMicros,
                    'cancelled',
                    new ChildRunSingleProgressContextDTO($only, $state, 'cancelled'),
                );
                $this->progressService->emitAndAdvance($batch->parentRunId, $update, $progressSeq);
            }
        } else {
            $this->progressService->emitAggregateProgress($batch, $snapshots, $progressSeq, $progressStartedMicros, 'cancelled');
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
            $this->agentRunner->cancel($only->childRunId, $policy->singleTimeoutCancelReason);
            $timeoutState = $this->childRunStore->get($only->childRunId);
            if (null !== $timeoutState) {
                $update = $this->progressService->buildProgressUpdate(
                    $batch,
                    $snapshots,
                    [$only->childRunId => $timeoutState->turnNo],
                    $progressSeq,
                    $progressStartedMicros,
                    'failed',
                    new ChildRunSingleProgressContextDTO($only, $timeoutState, 'failed'),
                );
                $this->progressService->emitAndAdvance($batch->parentRunId, $update, $progressSeq);
            }

            $this->lifecycleListener->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
                $only,
                AgentArtifactStatusEnum::Failed,
                failureReason: 'Child run timed out.',
                summary: 'Timed out after '.$batch->timeoutSeconds.'s.',
            ));
            $result = $this->lifecycleListener->timeoutToolResult($only, $batch->timeoutSeconds);

            return new ChildRunBatchSupervisionResultDTO($batch->parentRunId, array_values($snapshots), ChildRunBatchCompletionKindEnum::SingleTimedOut, $result);
        }

        foreach ($snapshots as $childRunId => $snapshot) {
            if ($snapshot->terminal) {
                continue;
            }

            $this->agentRunner->cancel($childRunId, $policy->parallelTimeoutCancelReason);
            $this->lifecycleListener->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
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
