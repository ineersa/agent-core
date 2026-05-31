<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessRepository;
use Psr\Log\LoggerInterface;

/**
 * Doctrine ORM-backed durable store for background process records.
 *
 * Query operations delegate to BackgroundProcessRepository.
 * Write operations (persist, flush, remove) use EntityManager directly.
 *
 * Schema is managed by Doctrine migrations — no runtime CREATE TABLE/ALTER TABLE.
 */
final class ProcessStore
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BackgroundProcessRepository $repository,
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
        $entity = $this->repository->findById($id);

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
        $entity = $this->repository->findByPid($pid);

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
        return $this->repository->findByPid($pid);
    }

    /**
     * Fetch a single entity by ID.
     */
    public function fetchById(int $id): ?BackgroundProcess
    {
        return $this->repository->findById($id);
    }

    /**
     * Fetch all entities, optionally filtered by session.
     *
     * @return BackgroundProcess[]
     */
    public function fetchAll(?string $sessionId = null): array
    {
        return $this->repository->findAll($sessionId);
    }

    /**
     * Fetch all unfinished entities (finishedAt IS NULL), optionally scoped by session.
     *
     * @return BackgroundProcess[]
     */
    public function fetchAllUnfinished(?string $sessionId = null): array
    {
        return $this->repository->findUnfinished($sessionId);
    }

    /**
     * Fetch all unfinished PIDs.
     *
     * @return int[]
     */
    public function fetchAllUnfinishedPids(): array
    {
        return $this->repository->findUnfinishedPids();
    }

    /**
     * Fetch stale entities where finishedAt is set and <= cutoff.
     *
     * @return BackgroundProcess[]
     */
    public function fetchStale(string $cutoff): array
    {
        return $this->repository->findStale($cutoff);
    }

    /**
     * Delete a single entity by ID.
     *
     * @return bool True if the entity was deleted, false if not found
     */
    public function deleteById(int $id): bool
    {
        $entity = $this->repository->findById($id);

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
}
