<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Psr\Log\LoggerInterface;

/**
 * Submit canonical snapshots to the owner and deliver controller transient snapshots.
 * Only consumed snapshots advance delivery markers; payload assembly stays separate.
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
     * Submit a forced interruption snapshot for owner-side once-only consumption.
     *
     * Parallel parent-cancel: aggregate parallel payload with status cancelled.
     * Single timeout/parent-cancel: flat single payload when child projection exists.
     * Owner consumption either commits progress or retires a superseded destination.
     */
    public function emitForcedInterruptionProgress(
        DeferredSubagentBatchProjectionDTO $batch,
        DeferredSubagentInterruptionKindEnum $kind,
    ): void {
        if ([] === $batch->children) {
            // Launch rejects empty tasks; reserveBatch inserts the batch and every
            // planned child in one transaction. Valid reservations cannot be empty.
            throw new \LogicException('Forced progress requires reserved child rows.');
        }

        if (ChildRunBatchExecutionModeEnum::Single === $batch->executionMode) {
            $payload = $this->snapshotFactory->buildSingleForcedPayload($batch, $kind);
        } else {
            if (DeferredSubagentInterruptionKindEnum::Timeout === $kind) {
                // Parallel timeout completion never calls this method: it has no
                // forced snapshot and does not wait for an interruption marker.
                return;
            }

            $payload = $this->snapshotFactory->buildForcedCancelPayload($batch);
        }

        $this->appendProgress($batch, $payload, 'deferred_subagent_batch.forced_interruption_progress_failed', $kind);
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

    private function appendProgress(DeferredSubagentBatchProjectionDTO $batch, SubagentProgressSnapshotInterface $payload, string $failureEventType, ?DeferredSubagentInterruptionKindEnum $kind = null): bool
    {
        try {
            return $this->progressEventAppender->append(
                parentRunId: $batch->parentRunId,
                parentTurnNo: $batch->parentTurnNo,
                parentToolCallId: $batch->parentToolCallId,
                parentOrderIndex: $batch->parentOrderIndex,
                toolName: 'subagent',
                progress: $payload,
                lifecycleId: $batch->lifecycleId,
                revision: $batch->aggregateProgressRevision,
                interruptionKind: $kind?->value,
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
    }

    private function markDeliveredRevision(DeferredSubagentBatchProjectionDTO $batch): bool
    {
        $current = $this->batchRepository->findByLifecycleId($batch->lifecycleId)
            ?? throw new \RuntimeException('Deferred subagent batch disappeared during progress delivery.');
        if ($current->deliveredProgressRevision >= $batch->aggregateProgressRevision) {
            return true;
        }
        try {
            $this->batchRepository->markDeliveredProgressRevision(
                batchLifecycleId: $batch->lifecycleId,
                deliveredProgressRevision: $batch->aggregateProgressRevision,
                expectedProjectionVersion: $current->projectionVersion,
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
