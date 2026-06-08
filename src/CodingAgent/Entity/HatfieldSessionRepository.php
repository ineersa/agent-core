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
    /** Whitelist of external sort keys → entity property names. */
    private const ALLOWED_SORT_FIELDS = [
        'updated_at' => 'updatedAt',
        'created_at' => 'createdAt',
        'prompt' => 'prompt',
        'name' => 'name',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HatfieldSession::class);
    }

    /**
     * Return sessions suitable for catalog/picker display.
     *
     * Whitelist-validated sort fields prevent arbitrary DQL injection.
     * Order is normalized to ASC or DESC (default DESC).  Limit is
     * clamped to the range 1..200 (default 50).
     *
     * @param string $sortBy External key: 'updated_at', 'created_at', 'prompt', or 'name'
     * @param int    $limit  Max results (1..200)
     * @param string $order  'ASC' or 'DESC' (case-insensitive)
     *
     * @return list<HatfieldSession>
     */
    public function findForCatalog(
        string $sortBy = 'updated_at',
        int $limit = 50,
        string $order = 'DESC',
    ): array {
        $property = self::ALLOWED_SORT_FIELDS[$sortBy] ?? 'updatedAt';
        $order = 'ASC' === strtoupper($order) ? 'ASC' : 'DESC';
        $limit = max(1, min(200, $limit));

        /* @var list<HatfieldSession> */
        return $this->createQueryBuilder('s')
            ->orderBy("s.{$property}", $order)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
