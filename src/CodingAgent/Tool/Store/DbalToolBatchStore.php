<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Store;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\CodingAgent\Entity\ToolBatchState;

/**
 * Doctrine ORM-backed durable batch store.
 *
 * Replaces the previous DBAL + raw SQL approach with standard Doctrine
 * ORM entity/repository pattern. Schema is managed by Doctrine migrations.
 */
final class DbalToolBatchStore implements ToolBatchStoreInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(string $runId, int $turnNo, string $stepId): ?array
    {
        $entity = $this->findEntity($runId, $turnNo, $stepId);

        if (null === $entity) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($entity->getBatchData(), true);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $batchState
     */
    public function save(string $runId, int $turnNo, string $stepId, array $batchState): void
    {
        $json = json_encode($batchState, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $now = date('c');

        $entity = $this->findEntity($runId, $turnNo, $stepId);

        if (null !== $entity) {
            // Update existing
            $entity->setBatchData($json);
            $entity->setUpdatedAt($now);
        } else {
            // Create new
            $entity = new ToolBatchState(
                runId: $runId,
                turnNo: $turnNo,
                stepId: $stepId,
                batchData: $json,
                createdAt: $now,
                updatedAt: $now,
            );
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    public function delete(string $runId, int $turnNo, string $stepId): void
    {
        $entity = $this->findEntity($runId, $turnNo, $stepId);

        if (null !== $entity) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntity(string $runId, int $turnNo, string $stepId): ?ToolBatchState
    {
        return $this->entityManager->find(
            ToolBatchState::class,
            ['runId' => $runId, 'turnNo' => $turnNo, 'stepId' => $stepId],
        );
    }
}
