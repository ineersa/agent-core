<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashProcessActionEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessSnapshotDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RequestBashBackgroundDTO;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessRepository;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\BashProcessRecordDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\ForegroundBashProcessDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Durable process snapshots and owner decisions use the existing DBAL connection.
 * Runtime reads do not retain managed process entities.
 * Schema is managed by Doctrine migrations.
 */
final class ProcessStore
{
    public const int PROVISIONAL_RETENTION_SECONDS = 300;

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
        $this->entityManager->flush();

        return $entity->id;
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

    public function sessionForRecord(int $id): ?string
    {
        $session = $this->entityManager->getConnection()->fetchOne('SELECT session_id FROM background_process WHERE id = ?', [$id]);

        return false === $session ? null : (string) $session;
    }

    /** Local acceptance runs only in the serialized in-process owner. */
    public function acceptLocal(int $recordId, ?string $runId): bool
    {
        return $this->entityManager->getConnection()->transactional(function () use ($recordId, $runId): bool {
            $connection = $this->entityManager->getConnection();
            $row = $connection->fetchAssociative('SELECT session_id FROM background_process WHERE id = ?', [$recordId]);
            if (false === $row || (null !== $runId && $row['session_id'] !== $runId)) {
                return false;
            }
            // Local callers can expose a process after observing its completion.
            // Preserve that contract without retaining an ORM identity-map alias.
            $now = Clock::get()->now()->format('Y-m-d H:i:s');
            $connection->executeStatement('UPDATE background_process SET backgrounded_at = COALESCE(backgrounded_at, ?), updated_at = ? WHERE id = ?', [$now, $now, $recordId]);

            return true;
        });
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

    /** @param list<string> $runIds
     * @return list<BashProcessRecordDTO>
     */
    public function unfinishedSnapshots(array $runIds): array
    {
        if ([] === $runIds) {
            return [];
        }

        return array_map($this->hydrateSnapshot(...), $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM background_process WHERE session_id IN (?) AND finished_at IS NULL ORDER BY id', [$runIds], [ArrayParameterType::STRING]));
    }

    /**
     * Fetch private foreground-supervision rows that are safely past their
     * five-minute cleanup grace interval.
     *
     * @param list<string> $sessionIds Controller-owned runs
     *
     * @return list<BashProcessRecordDTO>
     */
    public function fetchFinishedProvisionalBefore(\DateTimeImmutable $cutoff, array $sessionIds): array
    {
        if ([] === $sessionIds) {
            return [];
        }

        return array_map($this->hydrateSnapshot(...), $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM background_process WHERE session_id IN (?) AND backgrounded_at IS NULL AND finished_at IS NOT NULL AND finished_at <= ? ORDER BY id', [$sessionIds, $cutoff->format('Y-m-d H:i:s')], [ArrayParameterType::STRING]));
    }

    public function snapshot(int $recordId): ?BashProcessRecordDTO
    {
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM background_process WHERE id = ?', [$recordId]);

        return false === $row ? null : $this->hydrateSnapshot($row);
    }

    /** @return list<BashProcessRecordDTO> */
    public function backgroundSnapshots(string $runId): array
    {
        return array_map($this->hydrateSnapshot(...), $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM background_process WHERE session_id = ? AND backgrounded_at IS NOT NULL ORDER BY id DESC', [$runId]));
    }

    public function latestSnapshot(int $pid): ?BashProcessRecordDTO
    {
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM background_process WHERE pid = ? ORDER BY id DESC LIMIT 1', [$pid]);

        return false === $row ? null : $this->hydrateSnapshot($row);
    }

    /** @param list<string> $runIds
     * @return list<BashProcessRecordDTO>
     */
    public function completionSnapshots(array $runIds): array
    {
        if ([] === $runIds) {
            return [];
        }
        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM background_process WHERE session_id IN (?) AND backgrounded_at IS NOT NULL AND completion_notified_at IS NULL ORDER BY id', [$runIds], [ArrayParameterType::STRING]);

        return array_map($this->hydrateSnapshot(...), $rows);
    }

    /** Owner-only acknowledgement after durable completion admission. */
    public function markCompletionNotified(int $recordId, string $runId): void
    {
        $now = Clock::get()->now()->format('Y-m-d H:i:s');
        $this->entityManager->getConnection()->executeStatement('UPDATE background_process SET completion_notified_at = COALESCE(completion_notified_at, ?), updated_at = ? WHERE id = ? AND session_id = ?', [$now, $now, $recordId, $runId]);
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
            if (BashProcessActionEnum::DeleteFinished === $action || BashProcessActionEnum::DeleteProvisional === $action) {
                // Cleanup can only retire an immutable finished row. Recheck
                // scope and classification on metadata retry, never trust the
                // maintenance candidate as authority to delete another job.
                if (!$finished || (BashProcessActionEnum::DeleteProvisional === $action && (null !== $row['backgrounded_at'] || (string) $row['finished_at'] > Clock::get()->now()->modify('-'.self::PROVISIONAL_RETENTION_SECONDS.' seconds')->format('Y-m-d H:i:s')))) {
                    return null;
                }
                $connection->delete('background_process', ['id' => $recordId, 'session_id' => $runId]);

                return new BashProcessSnapshotDTO($recordId, $runId, (int) $row['pid'], null === $row['pgid'] ? null : (int) $row['pgid'], (string) $row['status_path'], true, $status, false);
            }
            $now = Clock::get()->now()->format('Y-m-d H:i:s');
            $signalRequired = false;
            if (!$finished) {
                $exitCode = $lifecycle->readStatusFile((string) $row['status_path']);
                $gone = null === $exitCode && !$lifecycle->isAlive((int) $row['pid']);
                $settledStop = (BashProcessActionEnum::SettleStop === $action && (bool) $row['stopped_by_user'])
                    || (BashProcessActionEnum::SettleTimeout === $action && BackgroundProcessStatusEnum::Stopped === $status);
                if (null !== $exitCode || $gone || $settledStop) {
                    $finished = true;
                    if (BackgroundProcessStatusEnum::Stopped !== $status) {
                        $status = null !== $exitCode ? BackgroundProcessStatusEnum::Finished : BackgroundProcessStatusEnum::FinishedUnclean;
                    }
                    $connection->executeStatement('UPDATE background_process SET finished_at = ?, exit_code = ?, status = ?, updated_at = ? WHERE id = ?', [$now, $exitCode, $status->value, $now, $recordId]);
                } elseif (BashProcessActionEnum::Timeout === $action && null !== $row['backgrounded_at'] && null !== $row['deadline_nanoseconds'] && hrtime(true) >= (int) $row['deadline_nanoseconds'] && !(bool) $row['stopped_by_user']) {
                    $status = BackgroundProcessStatusEnum::Stopped;
                    $signalRequired = true;
                    $connection->executeStatement('UPDATE background_process SET status = ?, updated_at = ? WHERE id = ?', [$status->value, $now, $recordId]);
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

    /** @param array<string, mixed> $row */
    private function hydrateSnapshot(array $row): BashProcessRecordDTO
    {
        return new BashProcessRecordDTO(
            (int) $row['id'], (int) $row['pid'], null === $row['pgid'] ? null : (int) $row['pgid'],
            (string) $row['session_id'], (string) $row['command'], (string) $row['log_path'], (string) $row['status_path'],
            new \DateTimeImmutable((string) $row['started_at']), null === $row['finished_at'] ? null : new \DateTimeImmutable((string) $row['finished_at']),
            null === $row['exit_code'] ? null : (int) $row['exit_code'], (bool) $row['stopped_by_user'],
            null === $row['backgrounded_at'] ? null : new \DateTimeImmutable((string) $row['backgrounded_at']),
            null === $row['tool_call_id'] ? null : (string) $row['tool_call_id'], (bool) $row['background_requested'],
            null === $row['completion_notified_at'] ? null : new \DateTimeImmutable((string) $row['completion_notified_at']),
            BackgroundProcessStatusEnum::from((string) $row['status']),
            null === $row['deadline_nanoseconds'] ? null : (int) $row['deadline_nanoseconds'],
        );
    }
}
