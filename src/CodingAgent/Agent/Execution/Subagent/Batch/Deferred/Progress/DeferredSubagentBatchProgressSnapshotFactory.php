<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummary;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

/** Pure aggregate payload assembly for deferred batch progress. Delivery owns revision, append, markers. */
final readonly class DeferredSubagentBatchProgressSnapshotFactory
{
    public function __construct(
        private SubagentChildProgressSummaryBuilder $childProgressSummaryBuilder,
        private SubagentProgressSnapshotBuilder $progressSnapshotBuilder,
        private ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    /**
     * Build the invocation progress payload.
     */
    public function buildNormalPayload(DeferredSubagentBatchProjectionDTO $batch): SubagentProgressSnapshotInterface
    {
        return $this->buildSingleNormalPayload($batch);
    }

    /**
     * Build forced progress payload for interruption (single-child only).
     *
     * Single timeout → flat 'failed' payload.
     * Single parent-cancel → flat 'cancelled' payload.
     */
    public function buildSingleForcedPayload(DeferredSubagentBatchProjectionDTO $batch, DeferredSubagentInterruptionKindEnum $kind): SubagentProgressSnapshotInterface
    {
        $child = $this->requireExactlyOneChild($batch);
        $cp = $child->childLifecycleProjection;
        $enrichment = null !== $cp
            ? $this->childProgressSummaryBuilder->fromDeferredProjection($cp, $child->artifactId)
            : $this->identityOnlyEnrichment($child);
        $forcedStatus = DeferredSubagentInterruptionKindEnum::Timeout === $kind ? 'failed' : 'cancelled';

        return $this->progressSnapshotBuilder->singleFromChildTurn(
            $child->agentName,
            $child->artifactId,
            $child->childRunId,
            $child->task,
            null !== $cp ? $cp->childTurnNo : 0,
            $this->childElapsedMs($child, $batch),
            $enrichment,
            $forcedStatus,
        );
    }

    private function requireExactlyOneChild(DeferredSubagentBatchProjectionDTO $batch): DeferredSubagentChildProjectionDTO
    {
        if (1 !== \count($batch->children)) {
            throw new \RuntimeException('Single batch progress requires exactly one child row.');
        }

        return $batch->children[0];
    }

    /**
     * Single-child flat normal payload: produces mode=single flat payload analogous to
     * the normalized single-batch terminal completion oracle.
     */
    private function buildSingleNormalPayload(DeferredSubagentBatchProjectionDTO $batch): SubagentProgressSnapshotInterface
    {
        $child = $this->requireExactlyOneChild($batch);
        $cp = $child->childLifecycleProjection;
        $enrichment = null !== $cp
            ? $this->childProgressSummaryBuilder->fromDeferredProjection($cp, $child->artifactId)
            : $this->identityOnlyEnrichment($child);

        $turnNo = null !== $cp ? $cp->childTurnNo : 0;
        $elapsed = $this->childElapsedMs($child, $batch);

        $status = null !== $cp
            ? $this->mapChildProgressStatus($cp->childStatus)
            : 'running';

        return $this->progressSnapshotBuilder->singleFromChildTurn(
            $child->agentName,
            $child->artifactId,
            $child->childRunId,
            $child->task,
            $turnNo,
            $elapsed,
            $enrichment,
            $status,
        );
    }

    private function identityOnlyEnrichment(DeferredSubagentChildProjectionDTO $child): SubagentChildProgressSummary
    {
        return $this->childProgressSummaryBuilder->fromLaunchIdentity(
            $child->launchModel,
            $child->launchReasoning,
            $child->artifactId,
        );
    }

    private function mapChildProgressStatus(RunStatus $status): string
    {
        return match ($status) {
            RunStatus::WaitingHuman => 'waiting_human',
            RunStatus::Completed => 'completed',
            RunStatus::Failed => 'failed',
            RunStatus::Cancelled, RunStatus::Cancelling => 'cancelled',
            default => 'running',
        };
    }

    private function childElapsedMs(
        DeferredSubagentChildProjectionDTO $child,
        DeferredSubagentBatchProjectionDTO $batch,
    ): int {
        $startedAt = $child->startedAt ?? $batch->startedAt;
        if (null === $startedAt) {
            return 0;
        }

        $endedAt = $child->terminalCompletedAt;
        if (null === $endedAt) {
            return $this->elapsedMsBetween($startedAt, $this->clock->now());
        }

        return $this->elapsedMsBetween($startedAt, $endedAt);
    }

    private function elapsedMsBetween(\DateTimeImmutable $startedAt, \DateTimeImmutable $endedAt): int
    {
        $delta = (float) $endedAt->format('U.u') - (float) $startedAt->format('U.u');

        return max(0, (int) round($delta * 1000));
    }
}
