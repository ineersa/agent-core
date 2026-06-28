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
 * Query operations delegate to BackgroundProcessRepository (ServiceEntityRepository)
 * which provides inherited find/findOneBy/findBy plus custom domain queries.
 * Write operations use EntityManager directly.
 *
 * Schema is managed by Doctrine migrations.
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
     * Timestamps (created_at, updated_at) are maintained by
     * TimestampableLifecycleTrait via lifecycle callbacks.
     *
     * @param array<string, mixed> $fields
     */
    public function insertRecord(array $fields): int
    {
        $entity = new BackgroundProcess();
        $entity->pid = (int) ($fields['pid'] ?? 0);
        $entity->pgid = isset($fields['pgid']) ? (int) $fields['pgid'] : null;
        $entity->sessionId = (string) ($fields['session_id'] ?? '');
        $entity->command = (string) ($fields['command'] ?? '');
        $entity->logPath = (string) ($fields['log_path'] ?? '');
        $entity->statusPath = (string) ($fields['status_path'] ?? '');
        $entity->startedAt = $fields['started_at'] instanceof \DateTimeImmutable
            ? $fields['started_at']
            : new \DateTimeImmutable((string) ($fields['started_at'] ?? 'now'));

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity->id;
    }

    /**
     * Mark a process as finished with an exit code.
     */
    public function markFinished(int $id, ?int $exitCode, \DateTimeImmutable $finishedAt): void
    {
        $entity = $this->fetchById($id);

        if (null === $entity) {
            throw new \RuntimeException(\sprintf('Background process record with ID %d not found.', $id));
        }

        $entity->finish($exitCode, $finishedAt);

        $this->entityManager->flush();
    }

    /**
     * Mark a process as stopped by user with finished timestamp.
     */
    public function markStoppedByUser(int $pid, \DateTimeImmutable $finishedAt): void
    {
        $entity = $this->repository
            ->findOneBy(['pid' => $pid]);

        if (null === $entity) {
            throw new \RuntimeException(\sprintf('Background process with PID %d not found.', $pid));
        }

        $entity->markStopped($finishedAt);

        $this->entityManager->flush();
    }

    /**
     * Mark a process as explicitly backgrounded (user accepted via prompt).
     */
    public function markBackgrounded(int $pid, \DateTimeImmutable $now): void
    {
        $entity = $this->fetchByPid($pid);

        if (null === $entity) {
            throw new \RuntimeException(\sprintf('Background process with PID %d not found.', $pid));
        }

        $entity->markBackgrounded($now);
        $this->entityManager->flush();
    }

    /**
     * Mark a process as notified of completion.
     */
    public function markCompletionNotified(int $pid, \DateTimeImmutable $now): void
    {
        $entity = $this->fetchByPid($pid);

        if (null === $entity) {
            throw new \RuntimeException(\sprintf('Background process with PID %d not found.', $pid));
        }

        $entity->markCompletionNotified($now);
        $this->entityManager->flush();
    }

    /**
     * Find background processes that finished and should notify on completion.
     *
     * Query conditions: finishedAt IS NOT NULL, backgroundedAt IS NOT NULL,
     * completionNotifiedAt IS NULL.
     *
     * @param string|null $sessionId optional session filter
     *
     * @return BackgroundProcess[]
     */
    public function findPendingNotifications(?string $sessionId = null): array
    {
        return $this->repository->findPendingNotifications($sessionId);
    }

    /**
     * Fetch a single entity by PID.
     *
     * When the ORM query returns null, logs a notice with EM-open
     * context for diagnostic use. Callers that need to distinguish
     * "row absent" from "ORM inconsistency" should call existsByPid()
     * separately.
     */
    public function fetchByPid(int $pid): ?BackgroundProcess
    {
        $entity = $this->repository->findOneBy(['pid' => $pid]);

        if (null === $entity) {
            $this->logFetchByPidNull($pid);
        }

        /* @var ?BackgroundProcess */
        return $entity;
    }

    /**
     * Fetch a single entity by auto-increment record ID.
     *
     * Unlike fetchByPid() which queries by OS PID (which can be reused),
     * this queries by the immutable auto-increment primary key.  Prefer
     * this when the DB id is available (e.g. from StartResult::$id).
     */
    public function fetchByRecordId(int $id): ?BackgroundProcess
    {
        $entity = $this->repository->find($id);

        if (null === $entity) {
            $this->logFetchByRecordIdNull($id);
        }

        /* @var ?BackgroundProcess */
        return $entity;
    }

    /**
     * Check whether a row exists for the given PID.
     *
     * Delegates to BackgroundProcessRepository::existsByPid() which uses an
     * ORM COUNT query that always hits the database, bypassing the identity
     * map.  This allows distinguishing "row does not exist" from "row exists
     * but ORM/identity map did not return it."
     */
    public function existsByPid(int $pid): bool
    {
        try {
            return $this->repository->existsByPid($pid);
        } catch (\Throwable $e) {
            $this->logger->warning('background_process.exists_by_pid_failed', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.exists_by_pid_failed',
                'process_pid' => $pid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check whether a row exists for the given record ID.
     *
     * Delegates to BackgroundProcessRepository::existsByRecordId() which
     * uses an ORM COUNT query that always hits the database.
     */
    public function existsByRecordId(int $id): bool
    {
        try {
            return $this->repository->existsByRecordId($id);
        } catch (\Throwable $e) {
            $this->logger->warning('background_process.exists_by_record_id_failed', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.exists_by_record_id_failed',
                'record_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Fetch a single entity by auto-increment ID.
     */
    public function fetchById(int $id): ?BackgroundProcess
    {
        /* @var ?BackgroundProcess */
        return $this->repository->find($id);
    }

    /**
     * Fetch all entities, optionally filtered by session.
     *
     * @return BackgroundProcess[]
     */
    public function fetchAll(?string $sessionId = null): array
    {
        $criteria = [];
        if (null !== $sessionId) {
            $criteria['sessionId'] = $sessionId;
        }

        /* @var BackgroundProcess[] */
        return $this->repository
            ->findBy($criteria, ['id' => 'DESC']);
    }

    /**
     * Fetch all unfinished entities, optionally scoped by session.
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
    public function fetchStale(\DateTimeImmutable $cutoff): array
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
        $entity = $this->fetchById($id);

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

    /**
     * Log a notice when fetchByPid returns null.
     *
     * Logs EM-open context for diagnostic use. Does not perform a
     * second existence check — callers that need to distinguish
     * "row absent" from "ORM inconsistency" should call existsByPid()
     * separately.
     */
    private function logFetchByPidNull(int $pid): void
    {
        $emOpen = $this->entityManager->isOpen();

        $this->logger->notice('background_process.fetch_by_pid_null', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.fetch_by_pid_null',
            'process_pid' => $pid,
            'em_open' => $emOpen,
        ]);
    }

    /**
     * Log a notice when fetchByRecordId returns null.
     */
    private function logFetchByRecordIdNull(int $id): void
    {
        $emOpen = $this->entityManager->isOpen();

        $this->logger->notice('background_process.fetch_by_record_id_null', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.fetch_by_record_id_null',
            'record_id' => $id,
            'em_open' => $emOpen,
        ]);
    }
}
