<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationResultDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessDTO;
use Ineersa\CodingAgent\Entity\BashRegistrationReceipt;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Owner-local registration operation. Transport publication is deliberately separate. */
final readonly class BashRegistrationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProcessStore $processes,
        private RunLockManager $locks,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
    ) {
    }

    public function register(RegisterBashProcessDTO $request): BashRegistrationResultDTO
    {
        $errors = $this->validator->validate($request);
        if (0 !== \count($errors)) {
            $fields = [];
            foreach ($errors as $error) {
                $fields[] = $error->getPropertyPath();
            }

            return new BashRegistrationResultDTO(BashRegistrationStatusEnum::InvalidInput, invalidFields: array_values(array_unique($fields)));
        }
        $hash = hash('sha256', $this->serializer->serialize($request, 'json', [DateTimeNormalizer::FORMAT_KEY => 'Y-m-d\\TH:i:s.uP']));

        return $this->locks->synchronized($request->ownerSessionId, fn (): BashRegistrationResultDTO => $this->entityManager->wrapInTransaction(function () use ($request, $hash): BashRegistrationResultDTO {
            $connection = $this->entityManager->getConnection();
            // Keep this fence and insertion in one write transaction. A competing
            // deletion must not be followed by registration from a stale snapshot.
            if (false === $connection->fetchOne('SELECT id FROM hatfield_session WHERE id = ?', [$request->ownerSessionId])) {
                return new BashRegistrationResultDTO(BashRegistrationStatusEnum::SessionMissing);
            }
            $receipt = $connection->fetchAssociative('SELECT * FROM bash_registration_receipt WHERE identity = ?', [$request->identity]);
            if (false !== $receipt) {
                if ((string) $receipt['owner_session_id'] !== $request->ownerSessionId || !hash_equals($receipt['input_hash'], $hash)) {
                    return new BashRegistrationResultDTO(BashRegistrationStatusEnum::InputConflict);
                }

                return new BashRegistrationResultDTO(BashRegistrationStatusEnum::Registered, (int) $receipt['record_id'], (int) $receipt['pid'], $receipt['log_path'], false !== $connection->fetchOne('SELECT id FROM background_process WHERE id = ?', [$receipt['record_id']]));
            }
            $fence = $connection->fetchAssociative('SELECT * FROM bash_launch_ownership WHERE identity = ?', [$request->identity]);
            if (false !== $fence) {
                return new BashRegistrationResultDTO((string) $fence['owner_session_id'] === $request->ownerSessionId ? BashRegistrationStatusEnum::Retired : BashRegistrationStatusEnum::InputConflict);
            }
            if ($this->processes->ownerSessionForRun($request->runId) !== $request->ownerSessionId) {
                return new BashRegistrationResultDTO(BashRegistrationStatusEnum::InputConflict);
            }
            $id = $this->processes->insertRecord([
                'pid' => $request->pid, 'pgid' => $request->pgid, 'session_id' => $request->runId,
                'command' => $request->command, 'tool_call_id' => $request->toolCallId,
                'deadline_nanoseconds' => $request->deadlineNanoseconds, 'background_requested' => $request->backgroundRequested,
                'log_path' => $request->logPath, 'status_path' => $request->statusPath, 'started_at' => $request->startedAt,
            ]);
            $receipt = new BashRegistrationReceipt();
            $receipt->identity = $request->identity;
            $receipt->owner = $this->entityManager->getReference(HatfieldSession::class, (int) $request->ownerSessionId);
            $receipt->inputHash = $hash;
            $receipt->recordId = $id;
            $receipt->pid = $request->pid;
            $receipt->logPath = $request->logPath;
            $this->entityManager->persist($receipt);

            return new BashRegistrationResultDTO(BashRegistrationStatusEnum::Registered, $id, $request->pid, $request->logPath, true);
        }));
    }
}
