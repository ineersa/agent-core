<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Deferred;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;

/** Settle acknowledged deferred sources only after durable background-completion receipt admission. */
final readonly class DeferredBackgroundCompletionSettlement
{
    public function __construct(
        private DeferredToolCompletionRepositoryInterface $deferred,
        private CompleteDeferredToolCallHandler $deferredHandler,
        private BackgroundCompletionReceiptRepository $receipts,
    ) {
    }

    /** Metadata only: joins the receipt and mailbox transaction. */
    public function recordAdmission(string $identity, string $sourceRunId): void
    {
        $deferredId = $this->deferredId($identity);
        if (null === $deferredId) {
            return;
        }
        $metadata = $this->deferred->findMetadataByDeferredId($deferredId);
        if (null === $metadata || $metadata->reference->runId !== $sourceRunId
            || null === $this->deferred->acknowledgedAt($deferredId)
            || null === $this->deferred->findAcceptedResultReferenceByDeferredId($deferredId)) {
            return;
        }
        $this->deferred->markCompleted($deferredId);
    }

    /** Retryable file cleanup, called only after verified owner finalization. */
    public function cleanup(string $identity): void
    {
        $deferredId = $this->deferredId($identity);
        $receipt = $this->receipts->receipt($identity);
        if (null === $deferredId || null === $receipt) {
            return;
        }
        $metadata = $this->deferred->findMetadataByDeferredId($deferredId);
        if (null === $metadata || 'completed' !== $metadata->status
            || $metadata->reference->runId !== $receipt->sourceRunId
            || null === $this->deferred->acknowledgedAt($deferredId)) {
            return;
        }
        $reference = $metadata->reference;
        $this->deferredHandler->settle($deferredId, $reference->runId, $reference->turnNo, $reference->stepId);
    }

    private function deferredId(string $identity): ?string
    {
        $id = match (true) {
            str_starts_with($identity, 'background-child-result-') => substr($identity, \strlen('background-child-result-')),
            str_starts_with($identity, 'background-') => substr($identity, \strlen('background-')),
            default => null,
        };

        return '' === $id ? null : $id;
    }
}
