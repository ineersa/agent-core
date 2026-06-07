<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine repository for BackgroundProcess entities.
 *
 * @extends ServiceEntityRepository<BackgroundProcess>
 */
final class BackgroundProcessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackgroundProcess::class);
    }

    /**
     * Find all unfinished processes (finishedAt IS NULL), optionally filtered by session.
     *
     * @return BackgroundProcess[]
     */
    public function findUnfinished(?string $sessionId = null): array
    {
        $qb = $this->createQueryBuilder('bp')
            ->where('bp.finishedAt IS NULL')
            ->orderBy('bp.id', 'DESC');

        if (null !== $sessionId) {
            $qb->andWhere('bp.sessionId = :sessionId')
                ->setParameter('sessionId', $sessionId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find unfinished PIDs only (no entity hydration).
     *
     * @return int[]
     */
    public function findUnfinishedPids(): array
    {
        $rows = $this->createQueryBuilder('bp')
            ->select('bp.pid')
            ->where('bp.finishedAt IS NULL')
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($rows, 'pid'));
    }

    /**
     * Find finished background processes that were explicitly backgrounded
     * and have not yet had their completion notified.
     *
     * These are processes that completed after being moved to background,
     * for which the BackgroundProcessCompletionPoller should send a
     * follow-up notification to the user.
     *
     * @param string|null $sessionId optional session filter
     *
     * @return BackgroundProcess[]
     */
    public function findPendingNotifications(?string $sessionId = null): array
    {
        $qb = $this->createQueryBuilder('bp')
            ->where('bp.finishedAt IS NOT NULL')
            ->andWhere('bp.backgroundedAt IS NOT NULL')
            ->andWhere('bp.completionNotifiedAt IS NULL');

        if (null !== $sessionId) {
            $qb->andWhere('bp.sessionId = :sessionId')
                ->setParameter('sessionId', $sessionId);
        }

        return $qb->orderBy('bp.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find stale (finished and older than cutoff) processes.
     *
     * @return BackgroundProcess[]
     */
    public function findStale(\DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.finishedAt IS NOT NULL')
            ->andWhere('bp.finishedAt <= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('bp.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
