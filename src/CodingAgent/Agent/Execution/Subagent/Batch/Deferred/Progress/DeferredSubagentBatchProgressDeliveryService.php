<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
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

    public function deliverIfNeeded(DeferredSubagentBatchProjectionDTO $batch): bool
    {
        if ($batch->aggregateProgressRevision <= $batch->deliveredProgressRevision) {
            return false;
        }

        if ([] === $batch->children) {
            return false;
        }

        $payload = $this->snapshotFactory->buildNormalPayload($batch);

        return $this->appendProgress($batch, $payload, 'deferred_subagent_batch.parent_progress_append_failed')
            && $this->markDeliveredRevision($batch);
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
            $this->logger->warning($failureEventType, [
                'batch_lifecycle_id' => $batch->lifecycleId,
                'parent_run_id' => $batch->parentRunId,
                'tool_call_id' => $batch->parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => $failureEventType,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        return true;
    }

    private function markDeliveredRevision(DeferredSubagentBatchProjectionDTO $batch): bool
    {
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
