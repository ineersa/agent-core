<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Psr\Log\LoggerInterface;

/**
 * Doctrine ORM-backed durable store for background process records.
 *
 * Replaces the previous DBAL + custom normalizer approach with standard
 * Doctrine ORM entity/repository pattern. Schema is managed by Doctrine
 * migrations, not runtime CREATE TABLE statements.
 *
 * The caller (BackgroundProcessManager) receives entity objects or
 * BackgroundProcessRecord DTOs via toRecord().
 */
final class ProcessStore
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Insert a new process record and return its auto-incremented ID.
     *
     * @param array<string, mixed> $fields Fields matching BackgroundProcess constructor
     *
     * @return int Auto-generated entity ID
     */
    public function insertRecord(array $fields): int
    {
        $entity = new BackgroundProcess(
            pid: (int) ($fields['pid'] ?? 0),
            pgid: isset($fields['pgid']) ? (int) $fields['pgid'] : null,
            sessionId: (string) ($fields['session_id'] ?? ''),
            command: (string) ($fields['command'] ?? ''),
            logPath: (string) ($fields['log_path'] ?? ''),
            statusPath: (string) ($fields['status_path'] ?? ''),
            startedAt: (string) ($fields['started_at'] ?? ''),
            updatedAt: (string) ($fields['updated_at'] ?? ''),
        );

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity->getId();
    }

    /**
     * Mark a process as finished with an exit code.
     */
    public function markFinished(int $id, ?int $exitCode, string $finishedAt): void
    {
        $entity = $this->entityManager->find(BackgroundProcess::class, $id);

        if (null === $entity) {
            throw new \RuntimeException(\sprintf('Background process record with ID %d not found.', $id));
        }

        $entity->setFinishedAt($finishedAt);
        $entity->setExitCode($exitCode);
        $entity->setUpdatedAt($finishedAt);

        $this->entityManager->flush();
    }

    /**
     * Mark a process as stopped by user with finished timestamp.
     */
    public function markStoppedByUser(int $pid, string $finishedAt): void
    {
        $entity = $this->findByPid($pid);

        if (null === $entity) {
            throw new \RuntimeException(\sprintf('Background process with PID %d not found.', $pid));
        }

        $entity->setStoppedByUser(true);
        $entity->setFinishedAt($finishedAt);
        $entity->setUpdatedAt($finishedAt);

        $this->entityManager->flush();
    }

    /**
     * Fetch a single entity by PID.
     */
    public function fetchByPid(int $pid): ?BackgroundProcess
    {
        return $this->findByPid($pid);
    }

    /**
     * Fetch a single entity by ID.
     */
    public function fetchById(int $id): ?BackgroundProcess
    {
        return $this->entityManager->find(BackgroundProcess::class, $id);
    }

    /**
     * Fetch all entities, optionally filtered by session.
     *
     * @return BackgroundProcess[]
     */
    public function fetchAll(?string $sessionId = null): array
    {
        $repo = $this->entityManager->getRepository(BackgroundProcess::class);

        if (null !== $sessionId) {
            return $repo->findBy(['sessionId' => $sessionId], ['id' => 'DESC']);
        }

        return $repo->findBy([], ['id' => 'DESC']);
    }

    /**
     * Fetch all unfinished entities (finishedAt IS NULL), optionally scoped by session.
     *
     * @return BackgroundProcess[]
     */
    public function fetchAllUnfinished(?string $sessionId = null): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('bp')
            ->from(BackgroundProcess::class, 'bp')
            ->where($qb->expr()->isNull('bp.finishedAt'))
            ->orderBy('bp.id', 'DESC');

        if (null !== $sessionId) {
            $qb->andWhere('bp.sessionId = :sessionId')
                ->setParameter('sessionId', $sessionId);
        }

        /** @var BackgroundProcess[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Fetch all unfinished PIDs.
     *
     * @return int[]
     */
    public function fetchAllUnfinishedPids(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('bp.pid')
            ->from(BackgroundProcess::class, 'bp')
            ->where($qb->expr()->isNull('bp.finishedAt'));

        $rows = $qb->getQuery()->getScalarResult();

        return array_map('intval', array_column($rows, 'pid'));
    }

    /**
     * Fetch stale entities where finishedAt is set and <= cutoff.
     *
     * @return BackgroundProcess[]
     */
    public function fetchStale(string $cutoff): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('bp')
            ->from(BackgroundProcess::class, 'bp')
            ->where($qb->expr()->isNotNull('bp.finishedAt'))
            ->andWhere('bp.finishedAt <= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('bp.id', 'DESC');

        /** @var BackgroundProcess[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Delete a single entity by ID.
     *
     * @return bool True if the entity was deleted, false if not found
     */
    public function deleteById(int $id): bool
    {
        $entity = $this->entityManager->find(BackgroundProcess::class, $id);

        if (null === $entity) {
            $this->logger->warning('background_process.delete_not_found', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.delete_not_found',
                'record_id' => $id,
            ]);

            return false;
        }

        $this->entityManager->remove($entity);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Flush pending entity changes to the database.
     */
    public function flush(): void
    {
        $this->entityManager->flush();
    }

    private function findByPid(int $pid): ?BackgroundProcess
    {
        $repo = $this->entityManager->getRepository(BackgroundProcess::class);

        /** @var ?BackgroundProcess $entity */
        $entity = $repo->findOneBy(['pid' => $pid]);

        return $entity;
    }
}
