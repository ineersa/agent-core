<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;

/**
 * Durable deferred batch delivery orchestration: aggregate progress and terminal completion.
 */
final readonly class DeferredSubagentBatchLifecycleDeliveryService
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private DeferredSubagentBatchProgressDeliveryService $progressDelivery,
        private DeferredSubagentBatchTerminalCompletionService $terminalCompletion,
    ) {
    }

    public function deliver(string $batchLifecycleId): void
    {
        $batch = $this->batchRepository->findByLifecycleId($batchLifecycleId);
        if (null === $batch) {
            return;
        }

        if (null !== $batch->terminalCompletionEnqueuedAt) {
            return;
        }

        if ($batch->aggregateProgressRevision > $batch->deliveredProgressRevision) {
            $this->progressDelivery->deliverIfNeeded($batch);
            $batch = $this->batchRepository->findByLifecycleId($batchLifecycleId);
            if (null === $batch || null !== $batch->terminalCompletionEnqueuedAt) {
                return;
            }
        }

        $this->terminalCompletion->completeIfAllTerminal($batch);
    }
}
