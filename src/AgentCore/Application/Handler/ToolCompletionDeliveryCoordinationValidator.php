<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryVerifier;
use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCompletionRecipientRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolCompletionDeliveryDTO;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionRecipientDTO;

/** Rejects invalid captured recipient facts before journaling and during recovery. */
final readonly class ToolCompletionDeliveryCoordinationValidator implements CoordinationActionValidatorInterface
{
    public function __construct(private ToolCompletionRecipientRepositoryInterface $recipients, private ToolCompletionDeliveryVerifier $sources)
    {
    }

    public function supports(object $action): bool
    {
        return $action instanceof RecordToolCompletionDeliveryDTO;
    }

    public function validate(object $action): void
    {
        if (!$action instanceof RecordToolCompletionDeliveryDTO) {
            throw new \InvalidArgumentException('Expected captured completion recipient projection.');
        }
        $delivery = $action->delivery;
        $reference = $delivery->reference;
        // Native-PHP journal decoding does not invoke DTO constructors.
        new ToolCompletionDeliveryReferenceDTO($reference->destinationRunId, $reference->messageId, $reference->sourceEvent, $reference->payloadFingerprint);
        new ToolCompletionRecipientDTO($reference, $delivery->receivedTurnNo, $delivery->receivedSequence, $delivery->disposition, $delivery->stateReason, $delivery->appliedSequence);
        new RecordToolCompletionDeliveryDTO($delivery, $action->expectedDisposition, $action->receiptEventOrdinal, $action->appliedEventOrdinal);
        $current = $this->recipients->findDelivery($reference->destinationRunId, $reference->messageId);
        if (null === $current) {
            if (null !== $action->expectedDisposition || null === $action->receiptEventOrdinal) {
                throw new \RuntimeException('A new recipient requires its captured canonical receipt ordinal.');
            }
            $this->sources->verify($reference->destinationRunId, $reference);

            return;
        }
        $current->assertSameReference($reference);
        if ($current->receivedSequence !== $delivery->receivedSequence || $current->receivedTurnNo !== $delivery->receivedTurnNo) {
            throw new \RuntimeException('Captured recipient receipt location conflicts with accepted evidence.');
        }
        if ($current->disposition === $delivery->disposition && $current->stateReason === $delivery->stateReason && $current->appliedSequence === $delivery->appliedSequence) {
            return;
        }
        if ($current->disposition !== $action->expectedDisposition || \in_array($current->disposition, [ToolCompletionDeliveryDispositionEnum::Applied, ToolCompletionDeliveryDispositionEnum::Rejected], true)) {
            throw new \RuntimeException('Captured recipient transition conflicts with accepted disposition.');
        }
    }
}
