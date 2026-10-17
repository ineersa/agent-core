<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/** Recipient facts rebuildable from canonical receipt and disposition events. */
final readonly class ToolCompletionRecipientDTO
{
    public function __construct(
        public ToolCompletionDeliveryReferenceDTO $reference,
        public int $receivedTurnNo,
        public ?int $receivedSequence = null,
        public ToolCompletionDeliveryDispositionEnum $disposition = ToolCompletionDeliveryDispositionEnum::Pending,
        public string $stateReason = 'awaiting_safe_boundary',
        public ?int $appliedSequence = null,
    ) {
        if ($receivedTurnNo < 0 || (null !== $receivedSequence && $receivedSequence < 1)
            || '' === trim($stateReason) || \strlen($stateReason) > 255
            || (null !== $appliedSequence && $appliedSequence < 1)
            || (ToolCompletionDeliveryDispositionEnum::Applied === $disposition) !== (null !== $appliedSequence)
            || (null === $receivedSequence && ToolCompletionDeliveryDispositionEnum::Pending !== $disposition)
            || (null !== $appliedSequence && null !== $receivedSequence && $appliedSequence <= $receivedSequence)
        ) {
            throw new \InvalidArgumentException('Invalid completion recipient disposition or receipt location.');
        }
    }

    public function withReceivedSequence(int $sequence): self
    {
        return new self($this->reference, $this->receivedTurnNo, $sequence, $this->disposition, $this->stateReason, $this->appliedSequence);
    }

    public function assertSameReference(ToolCompletionDeliveryReferenceDTO $reference): void
    {
        $accepted = $this->reference;
        if ($accepted->destinationRunId !== $reference->destinationRunId
            || $accepted->messageId !== $reference->messageId
            || !ToolCompletionDeliveryFenceDTO::same($accepted->deliveryFence, $reference->deliveryFence)
            || $accepted->payloadFingerprint !== $reference->payloadFingerprint
            || $accepted->sourceEvent->runId !== $reference->sourceEvent->runId
            || $accepted->sourceEvent->seq !== $reference->sourceEvent->seq
            || $accepted->sourceEvent->turnNo !== $reference->sourceEvent->turnNo
            || $accepted->sourceEvent->type !== $reference->sourceEvent->type) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Conflict, 'Completion reference conflicts with the accepted recipient receipt.');
        }
    }
}
