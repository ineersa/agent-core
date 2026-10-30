<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
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

    /** @return list<array<string, mixed>> */
    public function findOwnedBackgroundWork(string $rootRunId, ?int $observedRecordId): array
    {
        // Finished history is not loaded into the picker. A pending UI action
        // may additionally observe its exact retained row until native settlement.
        // child_run_id is unique, including resume rebinds. Resolve ownership
        // and its attribution in this statement without duplicating process rows.
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT process.id, process.pid, process.command, process.session_id,
                process.status, process.finished_at, process.started_at,
                child.agent_name AS owner_name, child.artifact_id AS owner_artifact_id
            FROM background_process process
            LEFT JOIN deferred_subagent_child child ON child.child_run_id = process.session_id
            LEFT JOIN deferred_subagent_batch batch ON batch.lifecycle_id = child.batch_lifecycle_id
            WHERE process.backgrounded_at IS NOT NULL
                AND (process.finished_at IS NULL OR process.id = :observed)
                AND (process.session_id = :root OR batch.parent_run_id = :root)
            ORDER BY process.id
            SQL, ['root' => $rootRunId, 'observed' => $observedRecordId]);
    }

    /**
     * Check whether a row exists for the given auto-increment record ID.
     *
     * Uses an ORM COUNT query that always hits the database, bypassing the
     * identity map for diagnostic purposes.
     */
    public function existsByRecordId(int $id): bool
    {
        return (bool) $this->createQueryBuilder('bp')
            ->select('COUNT(bp.id)')
            ->where('bp.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
