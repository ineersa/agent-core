<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\CodingAgent\Session\DTO\SessionCatalogFilterDTO;
use Pdo\Sqlite;
use Symfony\Component\Filesystem\Path;

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
     * Return all sessions sorted by updated_at DESC for catalog/picker display.
     *
     * No sort/order/limit parameters — callers get the full list in a
     * fixed order.  No DQL injection surface because the sort column and
     * direction are hard-coded.
     *
     * @return list<array{id: int, name: string, cwd: string, prompt: ?string, model: ?string, model_provider: ?string, model_name: ?string, reasoning: ?string, created_at: string, updated_at: string}>
     */
    public function findForCatalog(): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, name, cwd, prompt, model, model_provider, model_name, reasoning, created_at, updated_at FROM hatfield_session ORDER BY updated_at DESC, id DESC',
        );
    }

    /**
     * @param callable(string, string, ?string): string $displayTitle
     *
     * @return list<array{id: int, cwd: string, display_title: string, created_at: string, updated_at: string}>
     */
    public function findForCatalogPage(SessionCatalogFilterDTO $filter, callable $displayTitle): array
    {
        // Take the resolved ORM connection settings without connecting its writer.
        $params = $this->getEntityManager()->getConnection()->getParams();
        $path = $params['path'] ?? null;
        if ('pdo_sqlite' !== ($params['driver'] ?? null) || !\is_string($path) || '' === $path) {
            throw new \UnexpectedValueException('Expected a file-backed session database.');
        }
        if (!$this->catalogFileExists($path)) {
            return [];
        }

        // Keep SQLite's configured busy timeout, but never allow opening a writer
        // or creating a file (even if the store is removed after the check).
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $path,
            'driverOptions' => [Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY] + ($params['driverOptions'] ?? []),
        ]);
        try {
            $pdo = $connection->getNativeConnection();
            \assert($pdo instanceof Sqlite);
            // SQLite's built-in case folding is ASCII-only. Run the picker title
            // resolver in SQL so filtering happens before the bounded page is read.
            $pdo->createFunction('catalog_title', $displayTitle, 3);
            $pdo->createFunction('catalog_fold', static fn (string $text): string => mb_convert_case($text, \MB_CASE_FOLD, 'UTF-8'), 1);
            $pdo->createFunction('catalog_path', Path::canonicalize(...), 1);

            $qb = $connection->createQueryBuilder()
                ->select('id', 'cwd', 'catalog_title(CAST(id AS TEXT), name, prompt) AS display_title', 'created_at', 'updated_at')
                ->from('hatfield_session')
                ->orderBy('updated_at', 'DESC')
                ->addOrderBy('id', 'DESC')
                ->setFirstResult($filter->offset)
                ->setMaxResults($filter->limit + 1);
            if (null !== $filter->since) {
                $qb->andWhere('updated_at >= :since')->setParameter('since', $filter->since);
            }
            if (null !== $filter->until) {
                $qb->andWhere('updated_at < :until')->setParameter('until', $filter->until);
            }
            if (null !== $filter->query && '' !== $filter->query) {
                $qb->andWhere('(instr(catalog_fold(catalog_title(CAST(id AS TEXT), name, prompt)), catalog_fold(:query)) > 0 OR instr(CAST(id AS TEXT), :query) > 0)')->setParameter('query', $filter->query);
            }
            if (null !== $filter->cwd) {
                $qb->andWhere('catalog_path(cwd) = :cwd')->setParameter('cwd', $filter->cwd);
            }

            return $qb->fetchAllAssociative();
        } finally {
            $connection->close();
        }
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

    private function catalogFileExists(string $path): bool
    {
        if (is_file($path) && is_readable($path)) {
            return true;
        }
        if (file_exists($path) || is_link($path)) {
            throw new \UnexpectedValueException('Session database is not a readable file.');
        }
        // A failed stat beneath an inaccessible directory is not evidence that
        // the store is missing. Also reject invalid intermediate path entries.
        $parent = \dirname($path);
        for (; !is_dir($parent); $parent = \dirname($parent)) {
            if (file_exists($parent) || is_link($parent) || $parent === \dirname($parent)) {
                throw new \UnexpectedValueException('Session database directory is invalid.');
            }
        }
        if (!is_readable($parent) || !is_executable($parent)) {
            throw new \UnexpectedValueException('Session database directory is inaccessible.');
        }

        return false;
    }
}
