<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunProcessPort;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunProgressSinkPort;

/**
 * Progress snapshot assembly, deduplication signatures, committed emit path, and parent sequence advancement.
 */
final class ChildRunBatchProgressCoordinator
{
    public function __construct(
        private readonly ChildRunProgressSinkPort $progressSink,
        private readonly AgentChildParentSequenceCoordinator $sequenceCoordinator,
        private readonly ChildRunProcessPort $processPort,
    ) {
    }

    public function mapTerminalProgressStatus(RunState $state): string
    {
        return $this->progressSink->mapTerminalProgressStatus($state);
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     * @param array<string, int>                          $activeTurns
     */
    public function buildProgressUpdate(
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

    public function emitAndAdvance(string $parentRunId, ChildRunProgressUpdateDTO $update, int $progressSeq): void
    {
        $this->progressSink->emit($update);
        $this->sequenceCoordinator->advanceParentSequence($parentRunId, $progressSeq);
    }

    /**
     * @param-out int $progressSeq
     * @param-out string $lastSignature
     */
    public function emitDedupedIfChanged(
        string $parentRunId,
        ChildRunProgressUpdateDTO $update,
        int &$progressSeq,
        ?string &$lastSignature,
    ): void {
        $signature = $this->progressSink->progressSignature($update);
        if (null === $lastSignature || $signature !== $lastSignature) {
            $this->emitAndAdvance($parentRunId, $update, $progressSeq);
            ++$progressSeq;
            $lastSignature = $signature;
        }
    }

    /**
     * @param array<string, ChildRunBatchItemSnapshotDTO> $snapshots
     */
    public function emitAggregateProgress(ChildRunBatchDTO $batch, array $snapshots, int $progressSeq, int $progressStartedMicros, string $aggregateStatus): void
    {
        $update = $this->buildProgressUpdate($batch, $snapshots, $this->collectActiveTurns($snapshots), $progressSeq, $progressStartedMicros, $aggregateStatus);
        $this->emitAndAdvance($batch->parentRunId, $update, $progressSeq);
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
    public function resolveAggregateStatus(array $snapshots): string
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
}
