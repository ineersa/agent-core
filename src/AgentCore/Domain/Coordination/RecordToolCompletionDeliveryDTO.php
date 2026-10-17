<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Contract\CanonicalSequenceBoundActionInterface;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionRecipientDTO;

/** Captured recipient projection, applied in the mailbox metadata transaction. */
final readonly class RecordToolCompletionDeliveryDTO implements CanonicalSequenceBoundActionInterface
{
    public function __construct(
        public ToolCompletionRecipientDTO $delivery,
        public ?ToolCompletionDeliveryDispositionEnum $expectedDisposition = null,
        public ?int $receiptEventOrdinal = null,
        public ?int $appliedEventOrdinal = null,
    ) {
        if (null !== $appliedEventOrdinal && ($appliedEventOrdinal < 0 || ToolCompletionDeliveryDispositionEnum::Applied !== $delivery->disposition || null === $expectedDisposition)) {
            throw new \InvalidArgumentException('Only an applied delivery can bind its presentation event.');
        }
        if (null !== $receiptEventOrdinal && ($receiptEventOrdinal < 0 || null !== $expectedDisposition)) {
            throw new \InvalidArgumentException('Only a new recipient receipt can bind its event ordinal.');
        }
        if (null === $expectedDisposition && ToolCompletionDeliveryDispositionEnum::Pending !== $delivery->disposition) {
            throw new \InvalidArgumentException('A new recipient delivery must be pending.');
        }
        if (null === $receiptEventOrdinal && null === $delivery->receivedSequence) {
            throw new \InvalidArgumentException('Recipient projection requires a receipt location or allocated ordinal.');
        }
    }

    public function bindCanonicalSequences(array $sequences): object
    {
        if (null !== $this->appliedEventOrdinal) {
            $sequence = $sequences[$this->appliedEventOrdinal] ?? throw new \RuntimeException('Applied completion ordinal is outside its transition.');
            $delivery = $this->delivery;

            return new self(new ToolCompletionRecipientDTO($delivery->reference, $delivery->receivedTurnNo, $delivery->receivedSequence, $delivery->disposition, $delivery->stateReason, $sequence), $this->expectedDisposition, appliedEventOrdinal: $this->appliedEventOrdinal);
        }
        if (null === $this->receiptEventOrdinal) {
            return $this;
        }
        $sequence = $sequences[$this->receiptEventOrdinal] ?? throw new \RuntimeException('Recipient receipt ordinal is outside its transition.');
        if (null !== $this->delivery->receivedSequence) {
            if ($this->delivery->receivedSequence !== $sequence) {
                throw new \RuntimeException('Recipient receipt is already bound to another allocation.');
            }

            return $this;
        }

        return new self($this->delivery->withReceivedSequence($sequence), $this->expectedDisposition, $this->receiptEventOrdinal);
    }
}
