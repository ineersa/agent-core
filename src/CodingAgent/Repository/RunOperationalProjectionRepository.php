<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\RunOperationalStatusDTO;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\CodingAgent\Entity\RunOperationalState;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Doctrine repository for the disposable, payload-free run coordination projection.
 * This is deliberately not a RunState store and has no optimistic-CAS API.
 *
 * @extends ServiceEntityRepository<RunOperationalState>
 */
final class RunOperationalProjectionRepository extends ServiceEntityRepository implements RunOperationalStatusReaderInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct($registry, RunOperationalState::class);
    }

    public function replace(RunOperationalState $replacement): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->wrapInTransaction(function () use ($entityManager, $replacement): void {
            $state = $this->find($replacement->runId);
            if ($state instanceof RunOperationalState) {
                $state->replaceFrom($replacement);
            } else {
                $state = $replacement;
                $entityManager->persist($state);
            }

            $violations = $this->validator->validate($state);
            if (0 !== $violations->count()) {
                throw new ValidationFailedException($state, $violations);
            }

            $entityManager->flush();
        });
    }

    public function findOperationalStatus(string $runId): ?RunOperationalStatusDTO
    {
        $state = $this->find($runId);
        if (!$state instanceof RunOperationalState) {
            return null;
        }

        return new RunOperationalStatusDTO($state->runId, $state->status, $state->currentOperation());
    }

    public function deleteForOwnerSession(string $ownerSessionId): int
    {
        $states = $this->findBy(['ownerSessionId' => $ownerSessionId]);
        if ([] === $states) {
            return 0;
        }

        $entityManager = $this->getEntityManager();
        $entityManager->wrapInTransaction(static function () use ($entityManager, $states): void {
            foreach ($states as $state) {
                $entityManager->remove($state);
            }
            $entityManager->flush();
        });

        return \count($states);
    }
}
