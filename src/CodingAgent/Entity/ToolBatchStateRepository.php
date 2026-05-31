<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Repository for ToolBatchState entity lookups by domain key.
 *
 * ToolBatchState uses a surrogate auto-increment primary key.
 * Domain uniqueness (runId, turnNo, stepId) is enforced by DB unique constraint.
 * This repository wraps the findOneBy criteria for convenient DI.
 *
 * @see ToolBatchState
 */
final class ToolBatchStateRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Find a batch state by its domain composite key.
     */
    public function findByCompositeKey(string $runId, int $turnNo, string $stepId): ?ToolBatchState
    {
        /* @var ?ToolBatchState */
        return $this->entityManager->getRepository(ToolBatchState::class)
            ->findOneBy([
                'runId' => $runId,
                'turnNo' => $turnNo,
                'stepId' => $stepId,
            ]);
    }
}
