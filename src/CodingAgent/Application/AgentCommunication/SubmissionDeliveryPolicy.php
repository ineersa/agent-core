<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\AgentCommunication;

use Ineersa\AgentCore\Contract\CommandConsumptionPolicyInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Domain\Command\DTO\CommandConsumptionDecisionDTO;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\SubmissionProvenanceDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\SubmissionReceiptDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Session\CanonicalToolInvocationReader;

final readonly class SubmissionDeliveryPolicy implements CommandConsumptionPolicyInterface
{
    public function __construct(
        private SubmissionReceiptRepository $receipts,
        private DeferredSubagentChildRepository $children,
        private DeferredSubagentBatchRepository $batches,
        private AgentArtifactRegistry $artifacts,
        private RunOperationalStatusReaderInterface $statuses,
        private CanonicalToolInvocationReader $invocations,
    ) {
    }

    public function destination(SubmissionReceiptDTO $receipt): string
    {
        return 'agent_steer' === $receipt->toolName ? $receipt->childRunId : $receipt->parentRunId;
    }

    public function refusal(SubmissionReceiptDTO $receipt, RunState $state): ?string
    {
        if ($state->runId !== $this->destination($receipt)) {
            return 'Agent submission addressed to another recipient.';
        }
        $child = $this->children->findByChildRunId($receipt->childRunId);
        $batch = null === $child ? null : $this->batches->findByLifecycleId($child->batchLifecycleId);
        if (null === $batch || $batch->lifecycleId !== $receipt->lifecycleId || $batch->parentRunId !== $receipt->parentRunId
            || null !== $batch->interruptionKind || !$this->artifacts->belongsToCurrentParentLifetime($receipt->parentRunId, $child->artifactId)) {
            return 'Agent submission activity was superseded or interrupted.';
        }
        $origin = $this->invocations->find($receipt->parentRunId, $batch->parentToolCallId, requireCurrentStep: false);
        if (null === $origin || $origin->turnNo !== $batch->parentTurnNo) {
            return 'Agent submission origin is outside selected history.';
        }
        $parentStatus = $state->runId === $receipt->parentRunId ? $state->status : $this->statuses->findOperationalStatus($receipt->parentRunId)?->status;
        $childStatus = $state->runId === $receipt->childRunId ? $state->status : $this->statuses->findOperationalStatus($receipt->childRunId)?->status;
        if (null === $parentStatus || null === $childStatus
            || \in_array($parentStatus, [RunStatus::Failed, RunStatus::Cancelled, RunStatus::Cancelling], true)
            || \in_array($childStatus, [RunStatus::Failed, RunStatus::Cancelled, RunStatus::Cancelling], true)) {
            return 'Agent submission owner was cancelled or failed.';
        }
        if ('agent_steer' === $receipt->toolName && $childStatus->isTerminal()) {
            return 'Steering cannot resume a terminal child.';
        }
        if (RunStatus::Completed === $parentStatus) {
            if ('notify_parent' !== $receipt->toolName || !$receipt->backgrounded || $childStatus->isTerminal() || null !== $state->parentRunId) {
                return 'Only a current running background child may continue its idle parent.';
            }
        }

        return null;
    }

    public function prepare(RunState $state, PendingCommand $command): ?CommandConsumptionDecisionDTO
    {
        $provenance = $command->payload['provenance'] ?? null;
        if (!$provenance instanceof SubmissionProvenanceDTO) {
            return null;
        }
        $receipt = $this->receipts->receipt($provenance->sourceRunId, $provenance->identity)
            ?? throw new \RuntimeException('Agent delivery has no canonical source acceptance.');
        if ($command->idempotencyKey !== $receipt->identity) {
            throw new \RuntimeException('Agent delivery mailbox changed its source identity.');
        }
        $reason = $this->refusal($receipt, $state);

        return new CommandConsumptionDecisionDTO($reason, ['agent_communication' => ['identity' => $receipt->identity, 'source_run_id' => $receipt->sourceRunId]],
            defer: null === $reason && (RunStatus::WaitingHuman === $state->status || [] !== $state->pendingHumanInputRequests || RunStatus::Compacting === $state->status),
        );
    }
}
