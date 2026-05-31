<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Repository for BackgroundProcess entity queries.
 *
 * Dedicated query layer that keeps DQL and finder logic outside stores.
 * Stores coordinate persist/flush via EntityManager directly; queries
 * live here.
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
     * Find a process record by PID.
     */
    public function findByPid(int $pid): ?BackgroundProcess
    {
        /** @var ?BackgroundProcess $entity */
        $entity = $this->entityManager->getRepository(BackgroundProcess::class)
            ->findOneBy(['pid' => $pid]);

        return $entity;
    }

    /**
     * Find a process record by auto-incremented ID.
     */
    public function findById(int $id): ?BackgroundProcess
    {
        return $this->entityManager->find(BackgroundProcess::class, $id);
    }

    /**
     * Find all processes, optionally filtered by session.
     *
     * @return BackgroundProcess[]
     */
    public function findAll(?string $sessionId = null): array
    {
        $criteria = [];
        if (null !== $sessionId) {
            $criteria['sessionId'] = $sessionId;
        }

        /** @var BackgroundProcess[] $result */
        $result = $this->entityManager->getRepository(BackgroundProcess::class)
            ->findBy($criteria, ['id' => 'DESC']);

        return $result;
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
     * Find unfinished PIDs only.
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
