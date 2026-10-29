<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Application\Message\DTO\ReserveChildInvocationDTO;
use Ineersa\CodingAgent\Entity\ChildReservationRefusal;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Owner-local terminal refusal retention. Transport publication stays separate. */
final readonly class ChildReservationRefusalService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProcessStore $processes,
        private RunLockManager $locks,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
    ) {
    }

    public function retainedRefusal(ReserveChildInvocationDTO $request): ?string
    {
        $errors = $this->validator->validate($request);
        if (0 !== \count($errors)) {
            return null;
        }
        $hash = $this->inputHash($request);
        $owner = $this->processes->ownerSessionForRun($request->parentRunId);
        if (null === $owner) {
            return null;
        }

        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT owner_session_id, input_hash, refusal_message FROM child_reservation_refusal WHERE identity = ?',
            [$request->lifecycleId],
        );
        if (false === $row) {
            return null;
        }
        if ((string) $row['owner_session_id'] !== $owner || !hash_equals((string) $row['input_hash'], $hash)) {
            throw new ToolCallException('Child reservation refusal identity conflicts with a retained decision.', retryable: false);
        }

        return (string) $row['refusal_message'];
    }

    public function retain(ReserveChildInvocationDTO $request, string $refusalMessage): string
    {
        $errors = $this->validator->validate($request);
        if (0 !== \count($errors)) {
            throw new ToolCallException('Child reservation refusal request is invalid.', retryable: false);
        }
        $hash = $this->inputHash($request);
        $owner = $this->processes->ownerSessionForRun($request->parentRunId);
        if (null === $owner) {
            // Session deletion removed ownership; replay the provided refusal without
            // creating a new durable row that could outlive the deleted owner.
            return $refusalMessage;
        }

        return $this->locks->synchronized($owner, function () use ($request, $hash, $owner, $refusalMessage): string {
            $connection = $this->entityManager->getConnection();
            if (false === $connection->fetchOne('SELECT id FROM hatfield_session WHERE id = ?', [$owner])) {
                return $refusalMessage;
            }
            $existing = $connection->fetchAssociative(
                'SELECT owner_session_id, input_hash, refusal_message FROM child_reservation_refusal WHERE identity = ?',
                [$request->lifecycleId],
            );
            if (false !== $existing) {
                if ((string) $existing['owner_session_id'] !== $owner || !hash_equals((string) $existing['input_hash'], $hash)) {
                    throw new ToolCallException('Child reservation refusal identity conflicts with a retained decision.', retryable: false);
                }

                return (string) $existing['refusal_message'];
            }

            return $this->entityManager->wrapInTransaction(function () use ($request, $hash, $owner, $refusalMessage): string {
                $receipt = new ChildReservationRefusal();
                $receipt->identity = $request->lifecycleId;
                $receipt->owner = $this->entityManager->getReference(HatfieldSession::class, (int) $owner);
                $receipt->inputHash = $hash;
                $receipt->refusalMessage = $refusalMessage;
                $this->entityManager->persist($receipt);

                return $refusalMessage;
            });
        });
    }

    /** Missing owner session must refuse before any batch/child insert. */
    public function missingOwnerRefusal(ReserveChildInvocationDTO $request): ?string
    {
        $errors = $this->validator->validate($request);
        if (0 !== \count($errors)) {
            return null;
        }
        $owner = $this->processes->ownerSessionForRun($request->parentRunId);
        if (null === $owner) {
            return 'The child reservation owner session no longer exists.';
        }
        if (false === $this->entityManager->getConnection()->fetchOne('SELECT id FROM hatfield_session WHERE id = ?', [$owner])) {
            return 'The child reservation owner session no longer exists.';
        }

        return null;
    }

    private function inputHash(ReserveChildInvocationDTO $request): string
    {
        // Incarnation/correlation identify transport attempts, not the reserved
        // invocation. Bind only immutable reservation inputs.
        $payload = [
            'lifecycleId' => $request->lifecycleId,
            'parentRunId' => $request->parentRunId,
            'parentTurnNo' => $request->parentTurnNo,
            'parentToolCallId' => $request->parentToolCallId,
            'parentOrderIndex' => $request->parentOrderIndex,
            'deadlineAt' => $request->deadlineAt,
            'childIntent' => $request->childIntent,
            'parentModel' => $request->parentModel,
        ];

        return hash('sha256', $this->serializer->serialize($payload, 'json', [DateTimeNormalizer::FORMAT_KEY => 'Y-m-d\\TH:i:s.uP']));
    }
}
