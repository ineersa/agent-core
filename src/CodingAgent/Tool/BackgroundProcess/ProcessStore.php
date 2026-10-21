<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessRepository;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
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
        $entity->toolCallId = $fields['tool_call_id'] ?? null;
        $entity->deadlineNanoseconds = $fields['deadline_nanoseconds'] ?? null;
        $entity->logPath = (string) ($fields['log_path'] ?? '');
        $entity->statusPath = (string) ($fields['status_path'] ?? '');
        $entity->startedAt = $fields['started_at'] instanceof \DateTimeImmutable
            ? $fields['started_at']
            : new \DateTimeImmutable((string) ($fields['started_at'] ?? 'now'));

        $this->entityManager->persist($entity);
        $this->flush();

        return $entity->id;
    }

    /**
     * Find records needing status refresh or completion notification together.
     *
     * @param list<string> $sessionIds
     *
     * @return BackgroundProcess[]
     */
    public function findCompletionCandidates(array $sessionIds): array
    {
        return $this->repository->findCompletionCandidates($sessionIds);
    }

    /**
     * Fetch the newest retained entity for an OS PID (globally by id DESC).
     *
     * Used for public PID-addressed operations (bg_status). Session ownership
     * is enforced by callers after resolution. Foreground BashTool must use
     * fetchByRecordId() with the immutable primary key.
     */
    public function fetchLatestByPid(int $pid): ?BackgroundProcess
    {
        return $this->repository->findLatestByPid($pid);
    }

    /**
     * Fetch user-accepted background processes for one run.
     *
     * @return BackgroundProcess[]
     */
    public function fetchBackgrounded(string $sessionId): array
    {
        return $this->repository->findBackgrounded($sessionId);
    }

    /** @return list<BackgroundProcess> */
    public function fetchForegroundTools(string $sessionId): array
    {
        return $this->repository->findForegroundTools($sessionId);
    }

    /**
     * Fetch a user-accepted background process by PID within one run.
     */
    public function fetchBackgroundedByPid(string $sessionId, int $pid): ?BackgroundProcess
    {
        return $this->repository->findBackgroundedByPid($sessionId, $pid);
    }

    /**
     * Fetch a single entity by auto-increment record ID.
     *
     * Unlike PID-based lookups (OS PIDs can be reused while rows are retained),
     * this queries by the immutable auto-increment primary key.  Prefer
     * this when the DB id is available (e.g. from StartResult::$id).
     */
    public function fetchByRecordId(int $id): ?BackgroundProcess
    {
        return $this->repository->findFreshById($id);
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
     * Fetch all unfinished entities for the controller-owned runs.
     *
     * @param list<string> $sessionIds
     *
     * @return BackgroundProcess[]
     */
    public function fetchAllUnfinished(array $sessionIds): array
    {
        return $this->repository->findUnfinished($sessionIds);
    }

    /**
     * Fetch private foreground-supervision rows that are safely past their
     * five-minute cleanup grace interval.
     *
     * @param list<string> $sessionIds Controller-owned runs
     *
     * @return BackgroundProcess[]
     */
    public function fetchFinishedProvisionalBefore(\DateTimeImmutable $cutoff, array $sessionIds): array
    {
        return $this->repository->findFinishedProvisionalBefore($cutoff, $sessionIds);
    }

    /**
     * Delete a single entity by ID.
     *
     * @return bool True if the entity was deleted, false if not found
     */
    public function deleteById(int $id): bool
    {
        if (!$this->existsByRecordId($id)) {
            $this->logger->warning('background_process.delete_not_found', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.delete_not_found',
                'record_id' => $id,
            ]);

            return false;
        }

        $entity = $this->repository->find($id);

        if (null === $entity) {
            $this->logger->warning('background_process.delete_not_found', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.delete_not_found',
                'record_id' => $id,
            ]);

            return false;
        }

        $this->entityManager->remove($entity);
        $this->flush();

        return true;
    }

    /**
     * Flush pending entity changes to the database.
     */
    public function flush(): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $finishing = [];
        foreach ($unitOfWork->getIdentityMap()[BackgroundProcess::class] ?? [] as $entity) {
            if ($entity instanceof BackgroundProcess && null !== $entity->finishedAt
                && BackgroundProcessStatusEnum::Stopped !== $entity->status
                && null === ($unitOfWork->getOriginalEntityData($entity)['finishedAt'] ?? null)
            ) {
                $finishing[$entity->id] = $entity;
            }
        }
        if ([] === $finishing) {
            $this->entityManager->flush();

            return;
        }

        // The configured BEGIN IMMEDIATE transaction excludes competing writers
        // from this read through ORM flush. Refreshing before the transaction
        // would leave the same race between observing the cause and writing it.
        // Read only status so identity and unrelated pending fields stay intact.
        $this->entityManager->getConnection()->transactional(function () use ($finishing): void {
            foreach ($this->repository->findStoppedIds(array_keys($finishing)) as $id) {
                $finishing[$id]->status = BackgroundProcessStatusEnum::Stopped;
            }
            $this->entityManager->flush();
        });
    }
}
