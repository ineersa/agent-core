<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Standard DoctrineBundle repository for HatfieldSession entities.
 *
 * Extends ServiceEntityRepository — the standard Symfony/Doctrine base
 * for autowired repositories. Inherits find(), findOneBy(), findBy(),
 * and createQueryBuilder().
 *
 * DoctrineBundle auto-registers this repository via
 * #[ORM\Entity(repositoryClass: ...)] and injects ManagerRegistry.
 * The entity is mapped via public fields (native lazy objects).
 *
 * @extends ServiceEntityRepository<HatfieldSession>
 *
 * @see HatfieldSession
 */
final class HatfieldSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HatfieldSession::class);
    }

    /**
     * Authoritative persisted-session parentage, independent of disposable run
     * projections and of potentially stale ORM-managed entities.
     *
     * @return array{parent_run_id: ?string}|null
     */
    public function findRelationship(string $runId): ?array
    {
        // Native child UUIDs must not be coerced into numeric session IDs.
        if ('' === $runId || !ctype_digit($runId)) {
            return null;
        }

        // Bind the original string, not an int cast: oversized unknown IDs
        // must not saturate to an unrelated primary key.
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT parent_id FROM hatfield_session WHERE id = ?',
            [$runId],
        );

        return false === $row ? null : ['parent_run_id' => null === $row['parent_id'] ? null : (string) $row['parent_id']];
    }

    /**
     * Return catalog rows in fixed activity and numeric ID order.
     *
     * @return list<array{id: int, name: string, cwd: string, prompt: ?string, model: ?string, model_provider: ?string, model_name: ?string, reasoning: ?string, created_at: string, updated_at: string}>
     */
    public function findForCatalog(?\DateTimeImmutable $since = null, ?\DateTimeImmutable $until = null, ?int $limit = null, int $offset = 0): array
    {
        $query = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('id', 'name', 'cwd', 'prompt', 'model', 'model_provider', 'model_name', 'reasoning', 'created_at', 'updated_at')
            ->from('hatfield_session')
            ->orderBy('updated_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        // Doctrine persists activity at second precision in PHP's default timezone.
        $timezone = new \DateTimeZone(date_default_timezone_get());
        if (null !== $since) {
            $query->andWhere('updated_at >= :since')->setParameter('since', $since->setTimezone($timezone)->format('Y-m-d H:i:s'));
        }
        if (null !== $until) {
            $query->andWhere('updated_at < :until')->setParameter('until', $until->setTimezone($timezone)->format('Y-m-d H:i:s'));
        }

        return $query->fetchAllAssociative();
    }

    public function recordActivity(string $runId, \DateTimeImmutable $at): void
    {
        // Native children have UUID identities and no session catalog row.
        // Keep numeric IDs as strings so oversized unknown IDs cannot saturate.
        if ('' === $runId || !ctype_digit($runId)) {
            return;
        }

        // Do not hydrate stale ORM metadata or move recency backwards.
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE hatfield_session SET updated_at = :at WHERE id = :id AND updated_at < :at',
            ['at' => $at->format('Y-m-d H:i:s'), 'id' => $runId],
        );
    }

    /** Read fresh metadata across the TUI, controller and tool-worker processes. */
    public function findGoal(string $runId): ?string
    {
        if ('' === $runId || !ctype_digit($runId)) {
            return null;
        }
        $goal = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT goal FROM hatfield_session WHERE id = ? AND parent_id IS NULL',
            [$runId],
        );

        return \is_string($goal) ? $goal : null;
    }

    public function updateGoal(string $runId, ?string $goal): bool
    {
        if ('' === $runId || !ctype_digit($runId)) {
            return false;
        }

        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE hatfield_session SET goal = ? WHERE id = ? AND parent_id IS NULL',
            [$goal, $runId],
        );
    }

    public function clearGoalIfMatches(string $runId, string $expectedObjective): bool
    {
        if ('' === $runId || !ctype_digit($runId)) {
            return false;
        }

        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE hatfield_session SET goal = NULL WHERE id = ? AND parent_id IS NULL AND goal = ?',
            [$runId, $expectedObjective],
        );
    }

    /**
     * Return whether a session row currently exists in the database.
     *
     * Uses SQL so existence does not depend on a possibly stale managed
     * entity remaining in the identity map after another process deleted it.
     */
    public function existsById(int $id): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM hatfield_session WHERE id = ?', [$id]);
    }

    /** @return list<array{session_id: string, name: string, prompt: ?string}> */
    public function findForCompletion(string $prefix, int $limit): array
    {
        if ($limit < 1 || ('' !== $prefix && !ctype_digit($prefix))) {
            return [];
        }

        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('CAST(id AS TEXT) AS session_id', 'name', "CASE WHEN name = '' THEN prompt ELSE NULL END AS prompt")
            ->from('hatfield_session')
            ->where('CAST(id AS TEXT) LIKE :prefix')
            ->setParameter('prefix', $prefix.'%')
            ->orderBy('updated_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()->fetchAllAssociative();
    }
}
