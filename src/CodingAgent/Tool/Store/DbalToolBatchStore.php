<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Store;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\CodingAgent\Entity\ToolBatchState;
use Ineersa\CodingAgent\Entity\ToolBatchStateRepository;

/**
 * Doctrine ORM-backed durable batch store.
 *
 * Query operations delegate to ToolBatchStateRepository.
 * Write operations use EntityManager directly.
 * Schema is managed by Doctrine migrations — no runtime CREATE TABLE.
 */
final class DbalToolBatchStore implements ToolBatchStoreInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ToolBatchStateRepository $repository,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(string $runId, int $turnNo, string $stepId): ?array
    {
        $entity = $this->repository->findByCompositeKey($runId, $turnNo, $stepId);

        if (null === $entity) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($entity->batchData, true);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $batchState
     */
    public function save(string $runId, int $turnNo, string $stepId, array $batchState): void
    {
        $json = json_encode($batchState, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $now = date('c');

        $entity = $this->repository->findByCompositeKey($runId, $turnNo, $stepId);

        if (null !== $entity) {
            $entity->updateBatchData($json, $now);
        } else {
            $entity = new ToolBatchState();
            $entity->runId = $runId;
            $entity->turnNo = $turnNo;
            $entity->stepId = $stepId;
            $entity->batchData = $json;
            $entity->createdAt = $now;
            $entity->updatedAt = $now;
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    public function delete(string $runId, int $turnNo, string $stepId): void
    {
        $entity = $this->repository->findByCompositeKey($runId, $turnNo, $stepId);

        if (null !== $entity) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }
}
