<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Psr\Log\LoggerInterface;

/**
 * Progress delivery side-effects for deferred batches: revision gating, append, and
 * delivered-revision CAS marker. Pure payload assembly lives in the snapshot factory.
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
     * Emit exactly one forced parallel subagent_progress for parent cancellation, even when
     * the normal revision was already delivered. Preserves naturally terminal children;
     * overrides non-terminal and unprojected children to Cancelled + 'Cancelled by parent run.'.
     * Does not bump deliveredProgressRevision — the interruption_progress_enqueued_at marker
     * on the batch row guards dedup.
     */
    public function emitForcedParentCancelProgress(DeferredSubagentBatchProjectionDTO $batch): bool
    {
        if ([] === $batch->children) {
            return false;
        }

        $payload = $this->snapshotFactory->buildForcedCancelPayload($batch);

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
            $this->logger->warning('deferred_subagent_batch.forced_parent_cancel_progress_failed', [
                'batch_lifecycle_id' => $batch->lifecycleId,
                'parent_run_id' => $batch->parentRunId,
                'tool_call_id' => $batch->parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.forced_parent_cancel_progress_failed',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        return true;
    }

    public function deliverIfNeeded(DeferredSubagentBatchProjectionDTO $batch): bool
    {
        if ($batch->aggregateProgressRevision <= $batch->deliveredProgressRevision) {
            return false;
        }

        if ([] === $batch->children) {
            return false;
        }

        $payload = $this->snapshotFactory->buildNormalPayload($batch);

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
            $this->logger->warning('deferred_subagent_batch.parent_progress_append_failed', [
                'batch_lifecycle_id' => $batch->lifecycleId,
                'parent_run_id' => $batch->parentRunId,
                'tool_call_id' => $batch->parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.parent_progress_append_failed',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        try {
            $this->batchRepository->markDeliveredProgressRevision(
                batchLifecycleId: $batch->lifecycleId,
                deliveredProgressRevision: $batch->aggregateProgressRevision,
                expectedProjectionVersion: $batch->projectionVersion,
            );
        } catch (OptimisticLockException $exception) {
            $this->logger->warning('deferred_subagent_batch.delivered_progress_revision_conflict', [
                'batch_lifecycle_id' => $batch->lifecycleId,
                'parent_run_id' => $batch->parentRunId,
                'tool_call_id' => $batch->parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.delivered_progress_revision_conflict',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        return true;
    }
}
