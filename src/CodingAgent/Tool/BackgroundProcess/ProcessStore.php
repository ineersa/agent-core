<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashProcessActionEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessSnapshotDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RequestBashBackgroundDTO;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessRepository;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\ForegroundBashProcessDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

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
        $entity->backgroundRequested = (bool) ($fields['background_requested'] ?? false);
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

    /** @return list<ForegroundBashProcessDTO> */
    public function fetchForegroundTools(string $sessionId): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT id, pid, tool_call_id FROM background_process WHERE session_id = ? AND finished_at IS NULL AND tool_call_id IS NOT NULL AND backgrounded_at IS NULL AND background_requested = 0 ORDER BY id DESC', [$sessionId]);

        return array_map(static fn (array $row): ForegroundBashProcessDTO => new ForegroundBashProcessDTO(
            (int) $row['id'], (int) $row['pid'], (string) $row['tool_call_id'],
        ), $rows);
    }

    /** Owner-local, monotone metadata decision. No process waits or ORM hydration. */
    public function requestBackground(RequestBashBackgroundDTO $request, ProcessLifecycle $lifecycle): bool
    {
        if ('' === $request->runId || '' === $request->toolCallId || $request->recordId <= 0) {
            return false;
        }

        return $this->entityManager->getConnection()->transactional(function () use ($request, $lifecycle): bool {
            $connection = $this->entityManager->getConnection();
            $row = $connection->fetchAssociative('SELECT * FROM background_process WHERE id = ? AND session_id = ? AND tool_call_id = ?', [$request->recordId, $request->runId, $request->toolCallId]);
            if (false === $row) {
                return false;
            }
            // Redelivery recognizes committed intent even if the foreground
            // worker has since accepted the request or observed completion.
            if ((bool) $row['background_requested']) {
                return true;
            }
            if (null !== $row['finished_at'] || null !== $row['backgrounded_at']) {
                return false;
            }
            $exitCode = $lifecycle->readStatusFile((string) $row['status_path']);
            if (null !== $exitCode || !$lifecycle->isAlive((int) $row['pid'])) {
                $status = BackgroundProcessStatusEnum::Stopped->value === $row['status']
                    ? BackgroundProcessStatusEnum::Stopped
                    : (null !== $exitCode ? BackgroundProcessStatusEnum::Finished : BackgroundProcessStatusEnum::FinishedUnclean);
                $now = Clock::get()->now()->format('Y-m-d H:i:s');
                $connection->executeStatement('UPDATE background_process SET finished_at = ?, exit_code = ?, status = ?, updated_at = ? WHERE id = ?', [$now, $exitCode, $status->value, $now, $request->recordId]);

                return false;
            }
            $connection->executeStatement('UPDATE background_process SET background_requested = 1, updated_at = ? WHERE id = ?', [Clock::get()->now()->format('Y-m-d H:i:s'), $request->recordId]);

            return true;
        });
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

    public function sessionForRecord(int $id): ?string
    {
        $session = $this->entityManager->getConnection()->fetchOne('SELECT session_id FROM background_process WHERE id = ?', [$id]);

        return false === $session ? null : (string) $session;
    }

    /** Resolve the retained session, not the controller's transport identity. */
    public function ownerSessionForRun(string $runId): ?string
    {
        $connection = $this->entityManager->getConnection();
        $seen = [];
        while ('' !== $runId && !isset($seen[$runId])) {
            $seen[$runId] = true;
            $session = $connection->fetchOne('SELECT id FROM hatfield_session WHERE CAST(id AS TEXT) = ?', [$runId]);
            if (false !== $session) {
                return (string) $session;
            }
            $parent = $connection->fetchOne('SELECT parent_run_id FROM run_operational_state WHERE run_id = ?', [$runId]);
            if (!\is_string($parent)) {
                return null;
            }
            $runId = $parent;
        }

        return null;
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

    /** @param list<int> $ids */
    public function deleteByIds(array $ids): int
    {
        $deleted = 0;
        foreach (array_chunk(array_values(array_unique($ids)), 400) as $chunk) {
            $deleted += $this->entityManager->getConnection()->transactional(function () use ($chunk): int {
                // Query the database even for managed entities: another process may
                // already have deleted a row. Keep ORM removal/identity-map semantics.
                $entities = $this->repository->findBy(['id' => $chunk]);
                foreach ($entities as $entity) {
                    $this->entityManager->remove($entity);
                }
                if ([] !== $entities) {
                    $this->flush();
                }

                return \count($entities);
            });
        }

        return $deleted;
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

    public function isAcceptedRecord(int $recordId, string $runId): bool
    {
        return false !== $this->entityManager->getConnection()->fetchOne('SELECT id FROM background_process WHERE id = ? AND session_id = ? AND backgrounded_at IS NOT NULL', [$recordId, $runId]);
    }

    public function acceptedRecordId(string $runId, int $pid): ?int
    {
        $id = $this->entityManager->getConnection()->fetchOne('SELECT id FROM background_process WHERE session_id = ? AND pid = ? AND backgrounded_at IS NOT NULL ORDER BY id DESC LIMIT 1', [$runId, $pid]);

        return false === $id ? null : (int) $id;
    }

    public function decide(int $recordId, string $runId, BashProcessActionEnum $action, ProcessLifecycle $lifecycle): ?BashProcessSnapshotDTO
    {
        return $this->entityManager->getConnection()->transactional(function () use ($recordId, $runId, $action, $lifecycle): ?BashProcessSnapshotDTO {
            $connection = $this->entityManager->getConnection();
            $row = $connection->fetchAssociative('SELECT * FROM background_process WHERE id = ? AND session_id = ?', [$recordId, $runId]);
            if (false === $row) {
                return null;
            }
            $status = BackgroundProcessStatusEnum::from((string) $row['status']);
            $finished = null !== $row['finished_at'];
            $now = Clock::get()->now()->format('Y-m-d H:i:s');
            $signalRequired = false;
            if (!$finished) {
                $exitCode = $lifecycle->readStatusFile((string) $row['status_path']);
                $gone = null === $exitCode && !$lifecycle->isAlive((int) $row['pid']);
                $settledStop = BashProcessActionEnum::SettleStop === $action && (bool) $row['stopped_by_user'];
                if (null !== $exitCode || $gone || $settledStop) {
                    $finished = true;
                    if (BackgroundProcessStatusEnum::Stopped !== $status) {
                        $status = null !== $exitCode ? BackgroundProcessStatusEnum::Finished : BackgroundProcessStatusEnum::FinishedUnclean;
                    }
                    $connection->executeStatement('UPDATE background_process SET finished_at = ?, exit_code = ?, status = ?, updated_at = ? WHERE id = ?', [$now, $exitCode, $status->value, $now, $recordId]);
                } elseif (BashProcessActionEnum::Stop === $action) {
                    // A committed intent is not proof that native TERM occurred.
                    // Reconstruct the same obligation after reply loss; only the
                    // requester's single resolved Future resumes native execution.
                    $status = BackgroundProcessStatusEnum::Stopped;
                    $signalRequired = true;
                    if (!(bool) $row['stopped_by_user']) {
                        $connection->executeStatement('UPDATE background_process SET stopped_by_user = 1, status = ?, completion_notified_at = COALESCE(completion_notified_at, ?), updated_at = ? WHERE id = ?', [$status->value, $now, $now, $recordId]);
                    }
                }
            }

            return new BashProcessSnapshotDTO($recordId, $runId, (int) $row['pid'], null === $row['pgid'] ? null : (int) $row['pgid'], (string) $row['status_path'], $finished, $status, $signalRequired);
        });
    }
}
