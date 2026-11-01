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
            'SELECT id, name, cwd, prompt, model, model_provider, model_name, reasoning, created_at, updated_at FROM hatfield_session ORDER BY updated_at DESC',
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
            ->setMaxResults($limit)
            ->executeQuery()->fetchAllAssociative();
    }
}
