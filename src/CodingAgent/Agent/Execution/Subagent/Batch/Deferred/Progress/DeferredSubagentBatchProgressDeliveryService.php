<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Psr\Log\LoggerInterface;

/**
 * Progress delivery side-effects for deferred batches: claim-before-append revision
 * gating and best-effort parent progress append. Pure payload assembly lives in the
 * snapshot factory.
 */
final readonly class DeferredSubagentBatchProgressDeliveryService
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private DeferredSubagentBatchProgressSnapshotFactory $snapshotFactory,
        private SubagentProgressEventAppender $progressEventAppender,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Emit exactly one forced interruption progress payload.
     *
     * Parallel parent-cancel: aggregate parallel payload with status cancelled.
     * Single timeout/parent-cancel: flat single payload when child projection exists.
     * Does not bump deliveredProgressRevision — interruption_progress_enqueued_at guards dedup.
     *
     * @return bool true when a progress event was appended
     */
    public function emitForcedInterruptionProgress(
        DeferredSubagentBatchProjectionDTO $batch,
        DeferredSubagentInterruptionKindEnum $kind,
    ): bool {
        if ([] === $batch->children) {
            return false;
        }

        if (ChildRunBatchExecutionModeEnum::Single === $batch->executionMode) {
            $payload = $this->snapshotFactory->buildSingleForcedPayload($batch, $kind);
            if (null === $payload) {
                return false;
            }
        } else {
            if (DeferredSubagentInterruptionKindEnum::Timeout === $kind) {
                return false;
            }

            $payload = $this->snapshotFactory->buildForcedCancelPayload($batch);
        }

        return $this->appendProgress($batch, $payload, 'deferred_subagent_batch.forced_interruption_progress_failed');
    }

    /**
     * Deliver at most one progress snapshot for the batch's current aggregate revision.
     *
     * Algorithm:
     * 1. Skip when aggregate_progress_revision <= delivered_progress_revision or no children.
     * 2. Atomically claim the target revision (CAS on delivered_progress_revision).
     * 3. Append the progress event only after a successful claim.
     *
     * Invariants:
     * - At-most-once append per claimed revision across concurrent launch/tool/observe processes.
     * - Claim-before-append eliminates the previous append-then-CAS race that could duplicate.
     * - If append fails after claim, that revision is not retried; a later higher aggregate
     *   revision remains deliverable (latest-state recovery). Cross-store exactly-once with
     *   events.jsonl is not guaranteed — observability is best-effort.
     */
    public function deliverIfNeeded(DeferredSubagentBatchProjectionDTO $batch): bool
    {
        if ($batch->aggregateProgressRevision <= $batch->deliveredProgressRevision) {
            return false;
        }

        if ([] === $batch->children) {
            return false;
        }

        $targetRevision = $batch->aggregateProgressRevision;
        $claimed = $this->batchRepository->claimProgressDeliveryRevision(
            batchLifecycleId: $batch->lifecycleId,
            targetRevision: $targetRevision,
            expectedDeliveredRevision: $batch->deliveredProgressRevision,
        );
        if (!$claimed) {
            $this->logger->info('deferred_subagent_batch.progress_delivery_claim_lost', [
                'batch_lifecycle_id' => $batch->lifecycleId,
                'parent_run_id' => $batch->parentRunId,
                'tool_call_id' => $batch->parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.progress_delivery_claim_lost',
                'target_revision' => $targetRevision,
                'expected_delivered_revision' => $batch->deliveredProgressRevision,
            ]);

            return false;
        }

        $payload = $this->snapshotFactory->buildNormalPayload($batch);

        return $this->appendProgress($batch, $payload, 'deferred_subagent_batch.parent_progress_append_failed');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendProgress(DeferredSubagentBatchProjectionDTO $batch, array $payload, string $failureEventType): bool
    {
        try {
            $this->progressEventAppender->append(
                parentRunId: $batch->parentRunId,
                parentTurnNo: $batch->parentTurnNo,
                parentToolCallId: $batch->parentToolCallId,
                parentOrderIndex: $batch->parentOrderIndex,
                toolName: 'subagent',
                progress: $payload,
            );
        } catch (\Throwable $exception) {
            // Claim already advanced delivered_progress_revision. Do not roll back: concurrent
            // losers already lost the claim, and rolling back would re-open a duplicate race.
            // Recovery for operators is a later aggregate revision (child observe / launch bump).
            $this->logger->warning($failureEventType, [
                'batch_lifecycle_id' => $batch->lifecycleId,
                'parent_run_id' => $batch->parentRunId,
                'tool_call_id' => $batch->parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => $failureEventType,
                'exception_class' => $exception::class,
                'claimed_revision' => $batch->aggregateProgressRevision,
                'recovery' => 'later_aggregate_revision',
            ]);

            throw $exception;
        }

        return true;
    }
}
