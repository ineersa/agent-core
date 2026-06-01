<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine repository for ToolBatchState entity lookups.
 *
 * ToolBatchState uses a surrogate auto-increment primary key.
 * Domain uniqueness (runId, turnNo, stepId) is enforced by DB unique constraint.
 *
 * Extends ServiceEntityRepository for standard Symfony/Doctrine integration:
 * inherited find() / findOneBy() / findBy() / findAll() plus the domain
 * findByCompositeKey() method.
 *
 * @extends ServiceEntityRepository<ToolBatchState>
 *
 * @see ToolBatchState
 */
final class ToolBatchStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ToolBatchState::class);
    }

    /**
     * Find a batch state by its domain composite key.
     */
    public function findByCompositeKey(string $runId, int $turnNo, string $stepId): ?ToolBatchState
    {
        /* @var ?ToolBatchState */
        return $this->findOneBy([
            'runId' => $runId,
            'turnNo' => $turnNo,
            'stepId' => $stepId,
        ]);
    }
}
