<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Store;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Ineersa\CodingAgent\Entity\ToolBatchState;
use Ineersa\CodingAgent\Entity\ToolBatchStateRepository;

/**
 * Doctrine ORM-backed durable batch store.
 *
 * Query operations delegate to ToolBatchStateRepository (ServiceEntityRepository)
 * which provides inherited findOneBy plus the domain findByCompositeKey() method.
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

        $entity = $this->repository->findByCompositeKey($runId, $turnNo, $stepId);

        if (null !== $entity) {
            $entity->updateBatchData($json);
        } else {
            $entity = new ToolBatchState();
            $entity->runId = $runId;
            $entity->turnNo = $turnNo;
            $entity->stepId = $stepId;
            $entity->batchData = $json;
            // created_at/updated_at set by TimestampableLifecycleTrait lifecycle callbacks
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

    public function mutate(string $runId, int $turnNo, string $stepId, callable $callback): mixed
    {
        return $this->entityManager->wrapInTransaction(function () use ($runId, $turnNo, $stepId, $callback): mixed {
            $query = $this->entityManager->createQueryBuilder()
                ->select('e')
                ->from(ToolBatchState::class, 'e')
                ->where('e.runId = :runId')
                ->andWhere('e.turnNo = :turnNo')
                ->andWhere('e.stepId = :stepId')
                ->setParameter('runId', $runId)
                ->setParameter('turnNo', $turnNo)
                ->setParameter('stepId', $stepId)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE);

            /** @var ToolBatchState|null $entity */
            $entity = $query->getOneOrNullResult();

            $current = null;
            if (null !== $entity) {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($entity->batchData, true, 512, \JSON_THROW_ON_ERROR);
                $current = $decoded;
            }

            $outcome = $callback($current);
            if (!$outcome instanceof ToolBatchStoreMutation) {
                throw new \LogicException('Tool batch store mutate callback must return ToolBatchStoreMutation.');
            }

            if (null !== $outcome->nextSerializedState) {
                $json = json_encode($outcome->nextSerializedState, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

                if (null === $entity) {
                    $entity = new ToolBatchState();
                    $entity->runId = $runId;
                    $entity->turnNo = $turnNo;
                    $entity->stepId = $stepId;
                    $entity->batchData = $json;
                    $this->entityManager->persist($entity);
                } else {
                    $entity->updateBatchData($json);
                }
            }

            return $outcome->returnValue;
        });
    }
}
