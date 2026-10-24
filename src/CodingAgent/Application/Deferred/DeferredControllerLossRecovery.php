<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Deferred;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionReasonEnum;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;

/**
 * Park or retire deferred obligations after native controller ownership loss.
 * Native storage only supplies host facts; shared deferred ownership decides recovery.
 */
final readonly class DeferredControllerLossRecovery
{
    public function __construct(
        private CodeModeExecutionRepository $executions,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private ToolPayloadStoreInterface $payloads,
        private ApplicationDbTransactionInterface $transactions,
        private CompleteDeferredToolCallHandler $completions,
        private BackgroundCompletionReceiptRepository $receipts,
    ) {
    }

    /**
     * @param list<string> $identities
     */
    public function recover(array $identities, CodeModeInterruptionReasonEnum $reason): void
    {
        $cleanup = [];
        foreach ($identities as $identity) {
            $row = $this->executions->byIdentity($identity)
                ?? throw new \RuntimeException('Controller recovery lost its native host facts.');
            $currentReason = \is_string($row['interruption_reason'] ?? null) && '' !== $row['interruption_reason']
                ? CodeModeInterruptionReasonEnum::from($row['interruption_reason'])
                : null;
            $abandoned = \in_array($currentReason, [
                CodeModeInterruptionReasonEnum::Cancelled,
                CodeModeInterruptionReasonEnum::HistoryDiscarded,
            ], true);
            $pending = 'pending' === $this->deferred->status($identity);
            $parked = $this->deferred->requiresContinuation((string) $row['run_id'], (string) $row['tool_call_id']);
            $receipt = $this->receipts->receipt($identity);
            $admittedUnconsumed = null !== $receipt && \in_array($receipt->disposition, [
                BackgroundCompletionDispositionEnum::Pending,
                BackgroundCompletionDispositionEnum::Forwarding,
            ], true);
            if (!$pending && !$parked && !$admittedUnconsumed) {
                continue;
            }
            if ($abandoned) {
                $metadata = $this->deferred->findMetadataByDeferredId($identity)
                    ?? throw new \RuntimeException('Abandoned controller recovery lost its invocation metadata.');
                // The old controller can no longer produce a terminal. Retire
                // abandonment without fabricating output or weakening its fence.
                $this->transactions->transactional(function () use ($identity): void {
                    $this->deferred->requireContinuation($identity, false);
                    $this->deferred->markCompleted($identity);
                });
                $cleanup[$identity] = $metadata->reference;
                continue;
            }
            $this->transactions->transactional(function () use ($identity, $reason, $admittedUnconsumed): void {
                $this->deferred->requireContinuation($identity, true);
                $accepted = $this->deferred->findAcceptedResultReferenceByDeferredId($identity);
                if (null !== $accepted || $admittedUnconsumed) {
                    // Completed admitted notices keep accepted evidence and only
                    // need the shared continuation fence until explicit repair.
                    $this->executions->interrupt($identity, $reason);

                    return;
                }
                $reference = $this->deferred->findInvocationReferenceByDeferredId($identity)
                    ?? throw new \RuntimeException('Controller recovery lost its retained invocation.');
                $processed = $this->executions->isActive($identity) ? $this->payloads->findProcessedResultReference($reference) : null;
                if (null !== $processed) {
                    $result = $this->payloads->read($processed);
                    if (!$result instanceof ToolCallResult || null !== $result->pendingHumanInput) {
                        throw new \RuntimeException('Controller recovery requires terminal evidence.');
                    }
                    $accepted = $processed;
                } else {
                    $text = 'Code-mode execution was interrupted by controller ownership loss. No processed terminal result was retained; external effects may already have occurred. Execution was not retried.';
                    $result = new ToolCallResult($reference->runId, $reference->turnNo, $reference->stepId, $reference->attempt, $reference->idempotencyKey, $reference->toolCallId, $reference->orderIndex,
                        ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => $text]]],
                        true, ['type' => 'controller_interrupted', 'message' => $text, 'retryable' => false]);
                    $accepted = $this->payloads->retainProcessedResult($reference, $result);
                }
                // Immutable publication can leave an unreferenced file on
                // rollback, never accepted authority. Parking, first acceptance
                // and the old-host fence commit together.
                $this->deferred->acceptResult($identity, $accepted);
                $this->executions->interrupt($identity, $reason);
            });
        }
        // Immutable cleanup follows metadata commit. A duplicate controller
        // event retries guarded deletion.
        foreach ($cleanup as $identity => $reference) {
            $this->completions->settle($identity, $reference->runId, $reference->turnNo, $reference->stepId);
        }
    }
}
