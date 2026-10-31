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
        $entity->pid = \array_key_exists('pid', $fields) && null !== $fields['pid'] ? (int) $fields['pid'] : null;
        $entity->pgid = isset($fields['pgid']) ? (int) $fields['pgid'] : null;
        $entity->sessionId = (string) ($fields['session_id'] ?? '');
        $entity->command = (string) ($fields['command'] ?? '');
        $entity->toolCallId = $fields['tool_call_id'] ?? null;
        $entity->deadlineNanoseconds = $fields['deadline_nanoseconds'] ?? null;
        $entity->backgroundRequested = (bool) ($fields['background_requested'] ?? false);
        $entity->logPath = (string) ($fields['log_path'] ?? '');
        $entity->statusPath = (string) ($fields['status_path'] ?? '');
        if (\array_key_exists('started_at', $fields)) {
            $started = $fields['started_at'];
            $entity->startedAt = null === $started
                ? null
                : ($started instanceof \DateTimeImmutable ? $started : new \DateTimeImmutable((string) $started));
        } else {
            $entity->startedAt = null;
        }

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity->id;
    }

    /**
     * Owner-prepared native identity before launch.
     *
     * pid and started_at stay null until the worker launches against these paths.
     *
     * Reuses an existing prepared row for the exact status/log identity so
     * redelivery of the same invocation keeps the same paths and record id.
     * Distinct attempts allocate distinct paths. Deadline stays null until launch/ack.
     *
     * @param array{session_id: string, command: string, tool_call_id: string, log_path: string, status_path: string, background_requested?: bool} $fields
     */
    public function prepareRecord(array $fields): int
    {
        $existing = $this->findPreparedByStatusPath($fields['session_id'], $fields['status_path']);
        if (null !== $existing) {
            return $existing->id;
        }

        return $this->insertRecord([
            'pid' => null,
            'pgid' => null,
            'session_id' => $fields['session_id'],
            'command' => $fields['command'],
            'tool_call_id' => $fields['tool_call_id'],
            'deadline_nanoseconds' => null,
            'background_requested' => (bool) ($fields['background_requested'] ?? false),
            'log_path' => $fields['log_path'],
            'status_path' => $fields['status_path'],
            'started_at' => null,
        ]);
    }

    public function findPreparedByStatusPath(string $runId, string $statusPath): ?BashProcessRecordDTO
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT * FROM background_process WHERE session_id = ? AND status_path = ? LIMIT 1',
            [$runId, $statusPath],
        );

        return false === $row ? null : $this->hydrateSnapshot($row);
    }

    /**
     * Exact prepared row by record id, scoped to the owning run/toolCall.
     */
    public function findPreparedByRecordId(int $recordId, string $runId, string $toolCallId): ?BashProcessRecordDTO
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT * FROM background_process WHERE id = ? AND session_id = ? AND tool_call_id = ?',
            [$recordId, $runId, $toolCallId],
        );

        return false === $row ? null : $this->hydrateSnapshot($row);
    }

    /** @return list<BashProcessRecordDTO> */
    public function sessionSnapshots(string $runId): array
    {
        return array_map(
            $this->hydrateSnapshot(...),
            $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT * FROM background_process WHERE session_id = ? ORDER BY id ASC',
                [$runId],
            ),
        );
    }

    /**
     * Delete an unused owner-prepared native row that never launched.
     *
     * Metadata-only: requires null pid and started_at. Already-launched rows are untouched.
     */
    public function deleteUnusedPreparedRecord(int $recordId, string $runId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM background_process WHERE id = ? AND session_id = ? AND pid IS NULL AND started_at IS NULL AND backgrounded_at IS NULL AND finished_at IS NULL',
            [$recordId, $runId],
        );
    }

    /** Persist native launch facts onto an owner-prepared row. */
    public function applyLaunch(int $recordId, string $runId, int $pid, ?int $pgid, \DateTimeImmutable $startedAt, ?int $deadlineNanoseconds = null): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE background_process SET pid = ?, pgid = ?, started_at = ?, deadline_nanoseconds = COALESCE(?, deadline_nanoseconds), updated_at = ? WHERE id = ? AND session_id = ?',
            [
                $pid,
                $pgid,
                $startedAt->format('Y-m-d H:i:s.u'),
                $deadlineNanoseconds,
                Clock::get()->now()->format('Y-m-d H:i:s'),
                $recordId,
                $runId,
            ],
        );
    }

    public function applyFinishedByRecord(int $recordId, string $runId, ?int $exitCode, BackgroundProcessStatusEnum $status, bool $stoppedByUser, \DateTimeImmutable $finishedAt): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE background_process SET finished_at = COALESCE(finished_at, ?), exit_code = COALESCE(exit_code, ?), status = ?, stopped_by_user = CASE WHEN ? = 1 THEN 1 ELSE stopped_by_user END, updated_at = ? WHERE id = ? AND session_id = ?',
            [
                $finishedAt->format('Y-m-d H:i:s'),
                $exitCode,
                $status->value,
                $stoppedByUser ? 1 : 0,
                Clock::get()->now()->format('Y-m-d H:i:s'),
                $recordId,
                $runId,
            ],
        );
    }

    public function applyAcceptedByRecord(int $recordId, string $runId, \DateTimeImmutable $acceptedAt): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE background_process SET backgrounded_at = COALESCE(backgrounded_at, ?), updated_at = ? WHERE id = ? AND session_id = ?',
            [
                $acceptedAt->format('Y-m-d H:i:s'),
                Clock::get()->now()->format('Y-m-d H:i:s'),
                $recordId,
                $runId,
            ],
        );
    }

    public function recordIdForStatusPath(string $statusPath, string $runId): ?int
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM background_process WHERE status_path = ? AND session_id = ?',
            [$statusPath, $runId],
        );

        return false === $id ? null : (int) $id;
    }

    /** @return list<ForegroundBashProcessDTO> */
    public function fetchForegroundTools(string $sessionId): array
    {
        // Direct shell also persists tool_call_id. Only model Bash has an active
        // tool_batch_call row, so join that table for Ctrl+B eligibility.
        // Exclude older physical executions when a newer preparation exists.
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT p.id, p.pid, p.tool_call_id
             FROM background_process p
             INNER JOIN tool_batch_call c
                ON c.run_id = p.session_id
               AND c.tool_call_id = p.tool_call_id
             WHERE p.session_id = ?
               AND p.finished_at IS NULL
               AND p.tool_call_id IS NOT NULL
               AND p.backgrounded_at IS NULL
               AND p.background_requested = 0
               AND p.id = (
                    SELECT MAX(p2.id) FROM background_process p2
                    WHERE p2.session_id = p.session_id
                      AND p2.tool_call_id = p.tool_call_id
               )
             ORDER BY p.id DESC',
            [$sessionId],
        );

        return array_map(static fn (array $row): ForegroundBashProcessDTO => new ForegroundBashProcessDTO(
            (int) $row['id'], null === $row['pid'] ? null : (int) $row['pid'], (string) $row['tool_call_id'],
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
            $pid = $this->resolveObservedPid($row, $lifecycle);
            // Missing PID evidence means not-yet-known, never finished/dead.
            if (null !== $exitCode || (null !== $pid && !$lifecycle->isAlive($pid))) {
                $status = BackgroundProcessStatusEnum::Stopped->value === $row['status']
                    ? BackgroundProcessStatusEnum::Stopped
                    : (null !== $exitCode ? BackgroundProcessStatusEnum::Finished : BackgroundProcessStatusEnum::FinishedUnclean);
                $now = Clock::get()->now()->format('Y-m-d H:i:s');
                $connection->executeStatement('UPDATE background_process SET finished_at = ?, exit_code = ?, status = ?, updated_at = ? WHERE id = ?', [$now, $exitCode, $status->value, $now, $request->recordId]);

                return false;
            }
            if (null !== $pid && null === $row['pid']) {
                // Persist the learned wrapper PID without inventing start time/PGID.
                $connection->executeStatement(
                    'UPDATE background_process SET pid = ?, updated_at = ? WHERE id = ? AND pid IS NULL',
                    [$pid, Clock::get()->now()->format('Y-m-d H:i:s'), $request->recordId],
                );
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

                return new BashProcessSnapshotDTO($recordId, $runId, null === $row['pid'] ? null : (int) $row['pid'], null === $row['pgid'] ? null : (int) $row['pgid'], (string) $row['status_path'], true, $status, false);
            }
            $now = Clock::get()->now()->format('Y-m-d H:i:s');
            $signalRequired = false;
            $pid = $this->resolveObservedPid($row, $lifecycle);
            if (!$finished) {
                $exitCode = $lifecycle->readStatusFile((string) $row['status_path']);
                // Null PID evidence is not proof of death for owner-prepared rows.
                $gone = null === $exitCode && null !== $pid && !$lifecycle->isAlive($pid);
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
                    // A committed stop intent is not proof that native TERM occurred.
                    // Replaying the one-way owner command keeps the durable stop
                    // obligation; local callers still perform native signalling.
                    $status = BackgroundProcessStatusEnum::Stopped;
                    $signalRequired = true;
                    if (!(bool) $row['stopped_by_user']) {
                        $connection->executeStatement('UPDATE background_process SET stopped_by_user = 1, status = ?, updated_at = ? WHERE id = ?', [$status->value, $now, $recordId]);
                    }
                }
                if (null !== $pid && null === $row['pid']) {
                    $connection->executeStatement(
                        'UPDATE background_process SET pid = ?, updated_at = ? WHERE id = ? AND pid IS NULL',
                        [$pid, $now, $recordId],
                    );
                }
            }

            return new BashProcessSnapshotDTO($recordId, $runId, $pid, null === $row['pgid'] ? null : (int) $row['pgid'], (string) $row['status_path'], $finished, $status, $signalRequired && null !== $pid);
        });
    }

    /** @param array<string, mixed> $row */
    private function hydrateSnapshot(array $row): BashProcessRecordDTO
    {
        return new BashProcessRecordDTO(
            (int) $row['id'], null === $row['pid'] ? null : (int) $row['pid'], null === $row['pgid'] ? null : (int) $row['pgid'],
            (string) $row['session_id'], (string) $row['command'], (string) $row['log_path'], (string) $row['status_path'],
            null === $row['started_at'] ? null : new \DateTimeImmutable((string) $row['started_at']), null === $row['finished_at'] ? null : new \DateTimeImmutable((string) $row['finished_at']),
            null === $row['exit_code'] ? null : (int) $row['exit_code'], (bool) $row['stopped_by_user'],
            null === $row['backgrounded_at'] ? null : new \DateTimeImmutable((string) $row['backgrounded_at']),
            null === $row['tool_call_id'] ? null : (string) $row['tool_call_id'], (bool) $row['background_requested'],
            null === $row['completion_notified_at'] ? null : new \DateTimeImmutable((string) $row['completion_notified_at']),
            BackgroundProcessStatusEnum::from((string) $row['status']),
            null === $row['deadline_nanoseconds'] ? null : (int) $row['deadline_nanoseconds'],
        );
    }

    /**
     * Prefer the durable PID column; otherwise recover a positive validated PID
     * from the exact prepared .pid sidecar. Missing evidence means unknown.
     *
     * @param array<string, mixed> $row
     */
    private function resolveObservedPid(array $row, ProcessLifecycle $lifecycle): ?int
    {
        $pid = null === $row['pid'] ? null : (int) $row['pid'];
        if (null !== $pid && $pid > 0) {
            return $pid;
        }

        return $lifecycle->readPidFile((string) ($row['status_path'] ?? ''));
    }
}
