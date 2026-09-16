<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
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
     * @return list<HatfieldSession>
     */
    public function findForCatalog(): array
    {
        /* @var list<HatfieldSession> */
        return $this->createQueryBuilder('s')
            ->orderBy('s.updatedAt', 'DESC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /**
     * Return whether a session row currently exists in the database.
     *
     * Uses COUNT so existence does not depend on a possibly stale managed
     * entity remaining in the identity map after another process deleted it.
     */
    public function existsById(int $id): bool
    {
        return (bool) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
