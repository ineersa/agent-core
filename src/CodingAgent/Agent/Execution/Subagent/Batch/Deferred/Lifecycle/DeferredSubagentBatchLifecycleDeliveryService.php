<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle;

use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion\DeferredSubagentBatchCompletionDispatcher;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion\DeferredSubagentBatchTerminalCompletionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption\DeferredSubagentBatchInterruptionCompletionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress\DeferredSubagentBatchProgressDeliveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;

/**
 * Durable deferred batch delivery orchestration: progress and terminal completion.
 */
final readonly class DeferredSubagentBatchLifecycleDeliveryService
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private DeferredSubagentBatchProgressDeliveryService $progressDelivery,
        private DeferredSubagentBatchTerminalCompletionService $naturalCompletion,
        private DeferredSubagentBatchInterruptionCompletionService $interruptionCompletion,
        private DeferredSubagentBatchCompletionDispatcher $completionDispatcher,
    ) {
    }

    public function deliver(string $batchLifecycleId): void
    {
        $batch = $this->batchRepository->findByLifecycleId($batchLifecycleId);
        if (null === $batch || $this->routeBeforeNaturalCompletion($batch)) {
            return;
        }

        if ($batch->aggregateProgressRevision > $batch->deliveredProgressRevision) {
            $this->progressDelivery->deliverIfNeeded($batch);
            $batch = $this->batchRepository->findByLifecycleId($batchLifecycleId);
            if (null === $batch || $this->routeBeforeNaturalCompletion($batch)) {
                return;
            }
            if ($batch->aggregateProgressRevision > $batch->deliveredProgressRevision) {
                // The owner command is still queued. Consumption schedules
                // lifecycle delivery again after canonical progress commits.
                return;
            }
        }

        $this->naturalCompletion->completeIfTerminal($batch);
    }

    private function routeBeforeNaturalCompletion(DeferredSubagentBatchProjectionDTO $batch): bool
    {
        // Resume may already have moved the child to another invocation.
        // Retry the frozen result before checking for a child.
        if (null !== $batch->terminalCompletionEnqueuedAt || $this->completionDispatcher->retryFrozenCompletion($batch) || null === $batch->child) {
            return true;
        }

        if (null !== $batch->interruptionKind) {
            $this->interruptionCompletion->completeFromInterruption($batch);

            return true;
        }

        return false;
    }
}
