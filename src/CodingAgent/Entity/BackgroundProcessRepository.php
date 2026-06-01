<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine repository for BackgroundProcess entities.
 *
 * Extends ServiceEntityRepository for standard Symfony/Doctrine integration:
 * inherited find() / findOneBy() / findBy() / findAll() plus custom domain
 * queries in one place.
 *
 * @extends ServiceEntityRepository<BackgroundProcess>
 *
 * @see BackgroundProcess
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

        /* @var BackgroundProcess[] */
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
     * Find stale (finished and older than cutoff) processes.
     *
     * @return BackgroundProcess[]
     */
    public function findStale(string $cutoff): array
    {
        /* @var BackgroundProcess[] */
        return $this->createQueryBuilder('bp')
            ->where('bp.finishedAt IS NOT NULL')
            ->andWhere('bp.finishedAt <= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('bp.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
