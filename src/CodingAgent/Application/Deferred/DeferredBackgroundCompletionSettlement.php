<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Deferred;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionOriginEnum;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionReasonEnum;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;

/** Settle acknowledged deferred sources only after durable background-completion receipt admission. */
final readonly class DeferredBackgroundCompletionSettlement
{
    public function __construct(
        private DeferredToolCompletionRepositoryInterface $deferred,
        private CompleteDeferredToolCallHandler $deferredHandler,
        private BackgroundCompletionReceiptRepository $receipts,
        private CodeModeExecutionRepository $codeModeExecutions,
    ) {
    }

    /** Metadata only: joins the receipt and mailbox transaction. */
    public function recordAdmission(string $identity, string $sourceRunId, ?string $deferredId = null): void
    {
        $deferredId ??= $this->receipts->receipt($identity)?->deferredId;
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
    public function cleanup(string $identity, ?string $deferredId = null, ?BackgroundCompletionOriginEnum $origin = null, ?string $sourceToolCallId = null, ?string $sourceRunId = null): void
    {
        $receipt = $this->receipts->receipt($identity);
        $deferredId ??= $receipt?->deferredId;
        if (null === $deferredId && null !== $this->deferred->findMetadataByDeferredId($identity)) {
            $deferredId = $identity;
        }
        if (null === $deferredId) {
            return;
        }
        $metadata = $this->deferred->findMetadataByDeferredId($deferredId);
        if (null === $metadata) {
            return;
        }
        $origin ??= $receipt?->origin;
        $sourceToolCallId ??= null !== $receipt ? ($receipt->sourceToolCallId ?? $metadata->reference->toolCallId) : $metadata->reference->toolCallId;
        $sourceRunId ??= null !== $receipt ? $receipt->sourceRunId : $metadata->reference->runId;
        $interruption = BackgroundCompletionOriginEnum::CodeMode === $origin
            ? $this->codeModeExecutions->interruption($sourceRunId, $sourceToolCallId)
            : null;
        $interrupted = null !== $interruption && !\in_array($interruption, [
            CodeModeInterruptionReasonEnum::ControllerRestarted,
            CodeModeInterruptionReasonEnum::ControllerShutdown,
        ], true);
        if ($interrupted) {
            if (null !== $this->deferred->findAcceptedResultReferenceByDeferredId($deferredId)) {
                // An accepted terminal establishes physical completion. Retirement
                // does not require admitting an abandoned output to a new turn.
                $reference = $metadata->reference;
                $this->deferredHandler->settle($deferredId, $reference->runId, $reference->turnNo, $reference->stepId);
            }

            return;
        }
        if (null === $receipt || 'completed' !== $metadata->status
            || $metadata->reference->runId !== $receipt->sourceRunId
            || null === $this->deferred->acknowledgedAt($deferredId)) {
            return;
        }
        $reference = $metadata->reference;
        $this->deferredHandler->settle($deferredId, $reference->runId, $reference->turnNo, $reference->stepId);
    }
}
