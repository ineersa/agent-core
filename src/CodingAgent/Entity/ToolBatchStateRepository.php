<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Repository for ToolBatchState entity queries.
 *
 * ToolBatchState uses a composite primary key (runId, turnNo, stepId).
 * This repository wraps the composite-key find() for clean DI.
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
     * Find a batch state by its composite key.
     */
    public function findByCompositeKey(string $runId, int $turnNo, string $stepId): ?ToolBatchState
    {
        /* @var ?ToolBatchState */
        return $this->entityManager->find(
            ToolBatchState::class,
            ['runId' => $runId, 'turnNo' => $turnNo, 'stepId' => $stepId],
        );
    }
}
