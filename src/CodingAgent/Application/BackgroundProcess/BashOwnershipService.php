<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashOwnershipRequestDTO;
use Ineersa\CodingAgent\Entity\BashLaunchOwnership;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Owner-local transitions. Call retirement before signalling abandoned foreground work. */
final readonly class BashOwnershipService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RunLockManager $locks,
        private ValidatorInterface $validator,
    ) {
    }

    public function accept(BashOwnershipRequestDTO $request): BashOwnershipStatusEnum
    {
        return $this->transition($request, true);
    }

    public function retire(BashOwnershipRequestDTO $request): BashOwnershipStatusEnum
    {
        return $this->transition($request, false);
    }

    private function transition(BashOwnershipRequestDTO $request, bool $accept): BashOwnershipStatusEnum
    {
        if (0 !== \count($this->validator->validate($request))) {
            return BashOwnershipStatusEnum::InvalidInput;
        }

        return $this->locks->synchronized($request->ownerSessionId, fn (): BashOwnershipStatusEnum => $this->entityManager->wrapInTransaction(function () use ($request, $accept): BashOwnershipStatusEnum {
            $connection = $this->entityManager->getConnection();
            if (false === $connection->fetchOne('SELECT id FROM hatfield_session WHERE id = ?', [$request->ownerSessionId])) {
                return BashOwnershipStatusEnum::SessionMissing;
            }
            $fence = $connection->fetchAssociative('SELECT * FROM bash_launch_ownership WHERE identity = ?', [$request->identity]);
            if (false !== $fence) {
                if ((string) $fence['owner_session_id'] !== $request->ownerSessionId) {
                    return BashOwnershipStatusEnum::InputConflict;
                }

                // Acceptance is an ownership transfer, not a worker-local flag.
                // Retirement can never revoke it, including after a lost reply.
                if ((bool) $fence['accepted']) {
                    return BashOwnershipStatusEnum::Accepted;
                }
                if ($accept) {
                    return BashOwnershipStatusEnum::Retired;
                }
            }
            $receipt = $connection->fetchAssociative('SELECT * FROM bash_registration_receipt WHERE identity = ?', [$request->identity]);
            if (false !== $receipt && (string) $receipt['owner_session_id'] !== $request->ownerSessionId) {
                return BashOwnershipStatusEnum::InputConflict;
            }
            if ($accept) {
                if (false === $receipt) {
                    return BashOwnershipStatusEnum::RegistrationMissing;
                }
                $updated = $connection->executeStatement('UPDATE background_process SET backgrounded_at = COALESCE(backgrounded_at, ?) WHERE id = ? AND finished_at IS NULL AND stopped_by_user = 0', [Clock::get()->now()->format('Y-m-d H:i:s'), $receipt['record_id']]);
                if (0 === $updated) {
                    return false === $connection->fetchOne('SELECT id FROM background_process WHERE id = ?', [$receipt['record_id']])
                        ? BashOwnershipStatusEnum::ProcessMissing : BashOwnershipStatusEnum::ProcessFinished;
                }
            }
            if (false === $fence) {
                $fence = new BashLaunchOwnership();
                $fence->identity = $request->identity;
                $fence->owner = $this->entityManager->getReference(HatfieldSession::class, (int) $request->ownerSessionId);
                $fence->accepted = $accept;
                $this->entityManager->persist($fence);
            }

            // Retirement records no user-stop cause and acknowledges no completion.
            // Its tombstone also refuses registration which has not arrived yet.
            // A fence is not lasting signal permission: maintenance may remove
            // completed rows while the originating worker remains alive.
            if (!$accept && false !== $receipt) {
                $process = $connection->fetchAssociative('SELECT finished_at FROM background_process WHERE id = ?', [$receipt['record_id']]);
                if (false === $process) {
                    return BashOwnershipStatusEnum::ProcessMissing;
                }
                if (null !== $process['finished_at']) {
                    return BashOwnershipStatusEnum::ProcessFinished;
                }
            }

            return $accept ? BashOwnershipStatusEnum::Accepted : BashOwnershipStatusEnum::Retired;
        }));
    }
}
