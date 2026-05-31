<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Repository for BackgroundProcess entity queries.
 *
 * Only meaningful domain query methods are defined here.
 * Trivial find/findBy/findOneBy wrappers are omitted — callers use
 * EntityManager::find() or the built-in getRepository()->findBy() directly
 * when the criteria are simple.
 *
 * @see BackgroundProcess
 */
final class BackgroundProcessRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Find all unfinished processes (finishedAt IS NULL), optionally filtered by session.
     *
     * @return BackgroundProcess[]
     */
    public function findUnfinished(?string $sessionId = null): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('bp')
            ->from(BackgroundProcess::class, 'bp')
            ->where($qb->expr()->isNull('bp.finishedAt'))
            ->orderBy('bp.id', 'DESC');

        if (null !== $sessionId) {
            $qb->andWhere('bp.sessionId = :sessionId')
                ->setParameter('sessionId', $sessionId);
        }

        /** @var BackgroundProcess[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Find unfinished PIDs only (no entity hydration).
     *
     * @return int[]
     */
    public function findUnfinishedPids(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('bp.pid')
            ->from(BackgroundProcess::class, 'bp')
            ->where($qb->expr()->isNull('bp.finishedAt'));

        $rows = $qb->getQuery()->getScalarResult();

        return array_map('intval', array_column($rows, 'pid'));
    }

    /**
     * Find stale (finished and older than cutoff) processes.
     *
     * @return BackgroundProcess[]
     */
    public function findStale(string $cutoff): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('bp')
            ->from(BackgroundProcess::class, 'bp')
            ->where($qb->expr()->isNotNull('bp.finishedAt'))
            ->andWhere('bp.finishedAt <= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('bp.id', 'DESC');

        /** @var BackgroundProcess[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
