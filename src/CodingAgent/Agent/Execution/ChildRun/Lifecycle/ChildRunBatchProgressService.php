<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle;

use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunProgressUpdateDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunSingleProgressContextDTO;

/**
 * Progress snapshot assembly, deduplication signatures, and committed emit path.
 *
 * Parent RunEvent sequence allocation and RunState.lastSeq synchronization are owned
 * solely by CommittedRunEventAppender (via the committed EventStore allocator).
 */
final class ChildRunBatchProgressService
{
    public function __construct(
        private readonly ChildRunBatchLifecycleListenerInterface $lifecycleListener,
        private readonly RunStoreInterface $childRunStore,
    ) {
    }

    public function mapTerminalProgressStatus(RunState $state): string
    {
        return match ($state->status) {
            RunStatus::Completed => 'completed',
            RunStatus::Failed => 'failed',
            RunStatus::Cancelled, RunStatus::Cancelling => 'cancelled',
            default => 'done',
        };
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     * @param array<string, int>                          $activeTurns
     */
    public function buildProgressUpdate(
        ChildRunBatchDTO $batch,
        array $snapshots,
        array $activeTurns,
        int $progressStartedMicros,
        string $aggregateStatus,
        ?ChildRunSingleProgressContextDTO $singleContext = null,
    ): ChildRunProgressUpdateDTO {
        return new ChildRunProgressUpdateDTO(
            parentRunId: $batch->parentRunId,
            items: array_values($snapshots),
            activeTurns: $activeTurns,
            progressStartedMicros: $progressStartedMicros,
            aggregateStatus: $aggregateStatus,
            isSingleChild: $batch->isSingle(),
            singleContext: $singleContext,
        );
    }

    public function emitProgress(ChildRunProgressUpdateDTO $update): void
    {
        $this->lifecycleListener->emitProgress($update);
    }

    /**
     * @param-out string $lastSignature
     */
    public function emitDedupedIfChanged(
        ChildRunProgressUpdateDTO $update,
        ?string &$lastSignature,
    ): void {
        $signature = $this->lifecycleListener->progressSignature($update);
        if (null === $lastSignature || $signature !== $lastSignature) {
            $this->emitProgress($update);
            $lastSignature = $signature;
        }
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     */
    public function emitAggregateProgress(ChildRunBatchDTO $batch, array $snapshots, int $progressStartedMicros, string $aggregateStatus): void
    {
        $update = $this->buildProgressUpdate($batch, $snapshots, $this->collectActiveTurns($snapshots), $progressStartedMicros, $aggregateStatus);
        $this->emitProgress($update);
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     *
     * @return array<string, int>
     */
    public function collectActiveTurns(array $snapshots): array
    {
        $turns = [];
        foreach ($snapshots as $childRunId => $snapshot) {
            $state = $this->childRunStore->get($childRunId);
            if (null !== $state) {
                $turns[$childRunId] = $state->turnNo;
            }
        }

        return $turns;
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     */
    public function resolveAggregateStatus(array $snapshots): string
    {
        foreach ($snapshots as $snapshot) {
            if (!$snapshot->terminal) {
                return 'running';
            }
        }

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
}
