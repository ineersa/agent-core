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
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT process.id, process.pid, process.command, process.session_id,
                process.status, process.finished_at, process.started_at
            FROM background_process process
            WHERE process.backgrounded_at IS NOT NULL
                AND (process.finished_at IS NULL OR process.id = :observed)
                AND (process.session_id = :root OR process.session_id IN (
                    SELECT child.child_run_id FROM deferred_subagent_child child
                    JOIN deferred_subagent_batch batch ON batch.lifecycle_id = child.batch_lifecycle_id
                    WHERE batch.parent_run_id = :root
                ))
            ORDER BY process.id
            SQL, ['root' => $rootRunId, 'observed' => $observedRecordId]);
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
     * Fetch the newest retained row for an OS PID (globally, not session-scoped).
     *
     * OS PIDs are reused while historical rows are retained. Public
     * PID-addressed tools (bg_status) resolve the latest row by id DESC;
     * callers enforce session ownership after resolution. Foreground BashTool
     * must use immutable record IDs instead of this lookup.
     */
    public function findLatestByPid(int $pid): ?BackgroundProcess
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.pid = :pid')
            ->setParameter('pid', $pid)
            ->orderBy('bp.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
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

        return $qb->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /**
     * Find unfinished records and accepted completions needing notification.
     * Ownership comes from durable session/artifact identities: the operational
     * projection is cleared at controller startup while accepted processes live on.
     *
     * @param list<string> $sessionIds
     *
     * @return BackgroundProcess[]
     */
    public function findCompletionCandidates(array $sessionIds): array
    {
        if ([] === $sessionIds) {
            return [];
        }

        return $this->createQueryBuilder('bp')
            ->where('bp.sessionId IN (:sessionIds)')
            ->andWhere('bp.finishedAt IS NULL OR (bp.backgroundedAt IS NOT NULL AND bp.completionNotifiedAt IS NULL)')
            ->setParameter('sessionIds', $sessionIds)
            ->orderBy('bp.id', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /**
     * Find user-accepted background processes for one run.
     *
     * @return BackgroundProcess[]
     */
    public function findBackgrounded(string $sessionId, bool $unfinishedOnly = false): array
    {
        $query = $this->createQueryBuilder('bp')
            ->where('bp.sessionId = :sessionId')
            ->andWhere('bp.backgroundedAt IS NOT NULL')
            ->setParameter('sessionId', $sessionId)
            ->orderBy('bp.id', 'DESC');
        if ($unfinishedOnly) {
            $query->andWhere('bp.finishedAt IS NULL');
        }

        return $query->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /** @return list<BackgroundProcess> */
    public function findForegroundTools(string $sessionId): array
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.sessionId = :sessionId')
            ->andWhere('bp.finishedAt IS NULL')
            ->andWhere('bp.toolCallId IS NOT NULL')
            ->andWhere('bp.backgroundedAt IS NULL')
            ->andWhere('bp.backgroundRequested = :requested')
            ->setParameter('sessionId', $sessionId)
            ->setParameter('requested', false)
            ->orderBy('bp.id', 'DESC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /**
     * Resolve a user-accepted background process by PID within one run.
     */
    public function findBackgroundedByPid(string $sessionId, int $pid): ?BackgroundProcess
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.sessionId = :sessionId')
            ->andWhere('bp.pid = :pid')
            ->andWhere('bp.backgroundedAt IS NOT NULL')
            ->setParameter('sessionId', $sessionId)
            ->setParameter('pid', $pid)
            ->orderBy('bp.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * Find private foreground-supervision rows that have remained finished
     * for at least one cleanup interval.
     *
     * @return BackgroundProcess[]
     */
    public function findFinishedProvisionalBefore(\DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.backgroundedAt IS NULL')
            ->andWhere('bp.finishedAt IS NOT NULL')
            ->andWhere('bp.finishedAt <= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('bp.id', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /**
     * Load one process by primary key with committed field values.
     *
     * Prefer this over EntityManager::find() for mutable status reads so
     * identity-map copies are overwritten from SQL without a second query.
     */
    public function findFreshById(int $id): ?BackgroundProcess
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * Read only the authoritative stop cause without replacing pending changes.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function findStoppedIds(array $ids): array
    {
        return $this->createQueryBuilder('bp')
            ->select('bp.id')
            ->where('bp.id IN (:ids)')
            ->andWhere('bp.status = :status')
            ->setParameter('ids', $ids)
            ->setParameter('status', BackgroundProcessStatusEnum::Stopped)
            ->getQuery()
            ->getSingleColumnResult();
    }
}
