<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

use Doctrine\DBAL\Connection;
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
     * When the ORM query returns null, performs a one-shot direct SQL
     * existence check and logs diagnostics if the row exists but is not
     * visible through the ORM (identity map stale, closed EM, or SQLite
     * contention). This provides diagnostic evidence for #228 without
     * retry loops or masking genuine absence.
     */
    public function fetchByPid(int $pid): ?BackgroundProcess
    {
        $entity = $this->repository->findOneBy(['pid' => $pid]);

        if (null === $entity) {
            $this->logDiagnosticIfRowExistsByPid($pid);
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
            $this->logDiagnosticIfRowExistsById($id);
        }

        /* @var ?BackgroundProcess */
        return $entity;
    }

    /**
     * Check whether a row exists for the given PID using direct SQL,
     * bypassing the ORM identity map entirely.
     *
     * Useful as a diagnostic tool: if fetchByPid() returns null but
     * existsByPid() returns true, the ORM is not seeing a row that the
     * database holds — indicating an EM/identity-map issue.
     */
    public function existsByPid(int $pid): bool
    {
        try {
            return (bool) $this->dbal()->fetchOne(
                'SELECT COUNT(*) FROM background_process WHERE pid = ?',
                [$pid],
            );
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
     * Check whether a row exists for the given record ID using direct SQL.
     */
    public function existsByRecordId(int $id): bool
    {
        try {
            return (bool) $this->dbal()->fetchOne(
                'SELECT COUNT(*) FROM background_process WHERE id = ?',
                [$id],
            );
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
     * The DBAL connection is accessed on demand from the EntityManager
     * rather than injected directly, so ProcessStore is usable in all
     * contexts (including tests) without wiring a separate connection.
     */
    private function dbal(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * Log structured diagnostics when fetchByPid ORM lookup returns null
     * but the row exists at the SQL level.
     */
    private function logDiagnosticIfRowExistsByPid(int $pid): void
    {
        // Check if the EntityManager is still usable before the DBAL query
        $emOpen = $this->entityManager->isOpen();

        try {
            $rowExists = (bool) $this->dbal()->fetchOne(
                'SELECT COUNT(*) FROM background_process WHERE pid = ?',
                [$pid],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('background_process.fetch_by_pid_diagnostic_error', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.fetch_by_pid_diagnostic_error',
                'process_pid' => $pid,
                'em_open' => $emOpen,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($rowExists) {
            // Row exists in SQL but ORM missed it — this is the #228 signature
            $this->logger->error('background_process.fetch_by_pid_orm_miss', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.fetch_by_pid_orm_miss',
                'process_pid' => $pid,
                'em_open' => $emOpen,
                'diagnosis' => 'ORM fetchByPid returned null but direct SQL confirms row exists — identity map stale, EM closed, or SQLite contention',
            ]);
        }
    }

    /**
     * Log structured diagnostics when fetchByRecordId ORM lookup returns null
     * but the row exists at the SQL level.
     */
    private function logDiagnosticIfRowExistsById(int $id): void
    {
        $emOpen = $this->entityManager->isOpen();

        try {
            $rowExists = (bool) $this->dbal()->fetchOne(
                'SELECT COUNT(*) FROM background_process WHERE id = ?',
                [$id],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('background_process.fetch_by_record_id_diagnostic_error', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.fetch_by_record_id_diagnostic_error',
                'record_id' => $id,
                'em_open' => $emOpen,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($rowExists) {
            $this->logger->error('background_process.fetch_by_record_id_orm_miss', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.fetch_by_record_id_orm_miss',
                'record_id' => $id,
                'em_open' => $emOpen,
                'diagnosis' => 'ORM fetchByRecordId returned null but direct SQL confirms row exists — identity map stale, EM closed, or SQLite contention',
            ]);
        }
    }
}
