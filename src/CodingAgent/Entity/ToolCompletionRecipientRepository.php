<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\Tool\ToolCompletionRecipientRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolCompletionDeliveryDTO;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionRecipientDTO;
use Symfony\Component\Serializer\SerializerInterface;

/** @extends ServiceEntityRepository<ToolCompletionRecipient> */
final class ToolCompletionRecipientRepository extends ServiceEntityRepository implements ToolCompletionRecipientRepositoryInterface
{
    public function __construct(ManagerRegistry $registry, private readonly SerializerInterface $serializer)
    {
        parent::__construct($registry, ToolCompletionRecipient::class);
    }

    public function findDelivery(string $destinationRunId, string $messageId): ?ToolCompletionRecipientDTO
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative('SELECT * FROM tool_completion_recipient WHERE destination_run_id = ? AND message_id = ?', [$destinationRunId, $messageId]);
        if (false === $row) {
            return null;
        }
        $reference = $this->serializer->deserialize($row['reference_json'], ToolCompletionDeliveryReferenceDTO::class, 'json');
        if ($reference->destinationRunId !== $destinationRunId || $reference->messageId !== $messageId) {
            throw new \RuntimeException('Recipient projection reference differs from its store identity.');
        }

        return new ToolCompletionRecipientDTO($reference, (int) $row['received_turn_no'], (int) $row['received_sequence'], ToolCompletionDeliveryDispositionEnum::from($row['disposition']), $row['state_reason'], null === $row['applied_sequence'] ? null : (int) $row['applied_sequence']);
    }

    public function prepareProjection(RecordToolCompletionDeliveryDTO $action): \Closure
    {
        $referenceJson = $this->serializer->serialize($action->delivery->reference, 'json');

        return fn () => $this->applyProjection($action, $referenceJson);
    }

    private function applyProjection(RecordToolCompletionDeliveryDTO $action, string $referenceJson): void
    {
        $delivery = $action->delivery;
        $reference = $delivery->reference;
        if (null === $delivery->receivedSequence) {
            throw new \RuntimeException('Recipient projection requires an allocated committed receipt.');
        }
        $current = $this->findDelivery($reference->destinationRunId, $reference->messageId);
        if (null !== $current) {
            $current->assertSameReference($reference);
            if ($current->receivedTurnNo === $delivery->receivedTurnNo
                && $current->receivedSequence === $delivery->receivedSequence
                && $current->disposition === $delivery->disposition
                && $current->stateReason === $delivery->stateReason
                && $current->appliedSequence === $delivery->appliedSequence) {
                return;
            }
            if (null === $action->expectedDisposition || $current->disposition !== $action->expectedDisposition
                || $current->receivedSequence !== $delivery->receivedSequence || $current->receivedTurnNo !== $delivery->receivedTurnNo
                || \in_array($current->disposition, [ToolCompletionDeliveryDispositionEnum::Applied, ToolCompletionDeliveryDispositionEnum::Rejected], true)) {
                throw new \RuntimeException('Recipient projection conflicts with its accepted disposition.');
            }
            $this->getEntityManager()->getConnection()->update('tool_completion_recipient', [
                'disposition' => $delivery->disposition->value,
                'state_reason' => $delivery->stateReason,
                'applied_sequence' => $delivery->appliedSequence,
            ], ['destination_run_id' => $reference->destinationRunId, 'message_id' => $reference->messageId]);

            return;
        }
        if (null !== $action->expectedDisposition) {
            throw new \RuntimeException('Cannot change a missing recipient receipt.');
        }
        $this->getEntityManager()->getConnection()->insert('tool_completion_recipient', [
            'destination_run_id' => $reference->destinationRunId,
            'message_id' => $reference->messageId,
            'reference_json' => $referenceJson,
            'received_turn_no' => $delivery->receivedTurnNo,
            'received_sequence' => $delivery->receivedSequence,
            'disposition' => $delivery->disposition->value,
            'state_reason' => $delivery->stateReason,
            'applied_sequence' => $delivery->appliedSequence,
        ]);
    }
}
