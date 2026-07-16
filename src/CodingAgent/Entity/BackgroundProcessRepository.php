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
     * Check whether a row exists for the given PID.
     *
     * Uses an ORM COUNT query that always hits the database, bypassing the
     * identity map. This allows callers to distinguish "row does not exist"
     * from "row exists but ORM/identity map did not return it."
     */
    public function existsByPid(int $pid): bool
    {
        return (bool) $this->createQueryBuilder('bp')
            ->select('COUNT(bp.id)')
            ->where('bp.pid = :pid')
            ->setParameter('pid', $pid)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Check whether a row exists for the given auto-increment record ID.
     *
     * Uses an ORM COUNT query that always hits the database, bypassing the
     * identity map for diagnostic purposes.
     */
    /**
     * Fetch the newest retained row for an OS PID.
     *
     * OS PIDs are reused while historical rows are retained. Public
     * PID-addressed tools (bg_status) resolve the latest matching row
     * within the optional session scope. Foreground BashTool must use
     * immutable record IDs instead of this lookup.
     */
    public function findLatestByPid(int $pid, ?string $sessionId = null): ?BackgroundProcess
    {
        $qb = $this->createQueryBuilder('bp')
            ->where('bp.pid = :pid')
            ->setParameter('pid', $pid)
            ->orderBy('bp.id', 'DESC')
            ->setMaxResults(1);

        if (null !== $sessionId) {
            $qb->andWhere('bp.sessionId = :sessionId')
                ->setParameter('sessionId', $sessionId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function existsByRecordId(int $id): bool
    {
        return (bool) $this->createQueryBuilder('bp')
            ->select('COUNT(bp.id)')
            ->where('bp.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();
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
