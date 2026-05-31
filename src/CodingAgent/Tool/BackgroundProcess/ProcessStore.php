<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * SQLite-backed durable store for background process records.
 *
 * Handles all database operations: schema management, CRUD, and DTO
 * normalization. Pure data layer — no filesystem or OS process calls.
 * The caller (BackgroundProcessManager) enriches rows with filesystem
 * status before calling normalizeRow().
 */
final class ProcessStore
{
    /** @var string SQLite table name */
    private const TABLE = 'background_process';

    private bool $tableInitialized = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly DenormalizerInterface $denormalizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Ensure the background_process table exists.
     *
     * Creates the table if it doesn't exist and runs ALTER TABLE migrations
     * for schema additions (e.g. session_id column).
     */
    public function ensureTable(): void
    {
        if ($this->tableInitialized) {
            return;
        }

        try {
            $this->connection->executeStatement(
                'CREATE TABLE IF NOT EXISTS '.self::TABLE.' (
                    id             INTEGER PRIMARY KEY AUTOINCREMENT,
                    pid            INTEGER NOT NULL,
                    pgid           INTEGER,
                    session_id     TEXT NOT NULL DEFAULT \'\',
                    command        TEXT NOT NULL,
                    log_path       TEXT NOT NULL,
                    status_path    TEXT NOT NULL,
                    started_at     TEXT NOT NULL,
                    finished_at    TEXT,
                    exit_code      INTEGER,
                    stopped_by_user INTEGER NOT NULL DEFAULT 0,
                    updated_at     TEXT NOT NULL
                )'
            );

            // Add session_id column if table already existed without it
            // (SQLite migration: ALTER TABLE ADD COLUMN is a no-op if
            //  the column already exists, but we wrap in try/catch for
            //  safety, as some versions may error on duplicate column.)
            try {
                $this->connection->executeStatement(
                    'ALTER TABLE '.self::TABLE.' ADD COLUMN session_id TEXT NOT NULL DEFAULT \'\'',
                );
            } catch (DbalException $e) {
                $this->logger->debug('background_process migration: column likely already exists', [
                    'exception' => $e->getMessage(),
                ]);
            }

            $this->tableInitialized = true;
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to create background_process table.', 0, $e);
        }
    }

    /**
     * Insert a new process record and return its auto-incremented ID.
     *
     * @param array<string, mixed> $fields Fields to insert
     *
     * @return int Last insert ID
     */
    public function insertRecord(array $fields): int
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO '.self::TABLE.' (pid, pgid, session_id, command, log_path, status_path, started_at, updated_at)
                 VALUES (:pid, :pgid, :session_id, :command, :log_path, :status_path, :started_at, :updated_at)',
                [
                    'pid' => $fields['pid'],
                    'pgid' => $fields['pgid'],
                    'session_id' => $fields['session_id'] ?? '',
                    'command' => $fields['command'],
                    'log_path' => $fields['log_path'],
                    'status_path' => $fields['status_path'],
                    'started_at' => $fields['started_at'],
                    'updated_at' => $fields['updated_at'],
                ],
            );

            return (int) $this->connection->lastInsertId();
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to insert background process record.', 0, $e);
        }
    }

    /**
     * Mark a process as finished with an exit code.
     */
    public function markFinished(int $id, ?int $exitCode, string $finishedAt): void
    {
        try {
            $this->connection->executeStatement(
                'UPDATE '.self::TABLE.' SET finished_at = :finished_at, exit_code = :exit_code, updated_at = :updated_at WHERE id = :id',
                [
                    'finished_at' => $finishedAt,
                    'exit_code' => $exitCode,
                    'updated_at' => $finishedAt,
                    'id' => $id,
                ],
            );
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to update background process finished status.', 0, $e);
        }
    }

    /**
     * Mark a process as stopped by user with finished timestamp.
     */
    public function markStoppedByUser(int $pid, string $finishedAt): void
    {
        try {
            $this->connection->executeStatement(
                'UPDATE '.self::TABLE.' SET stopped_by_user = 1, finished_at = :finished_at, updated_at = :updated_at WHERE pid = :pid',
                [
                    'finished_at' => $finishedAt,
                    'updated_at' => $finishedAt,
                    'pid' => $pid,
                ],
            );
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to update background process stop record.', 0, $e);
        }
    }

    /**
     * Fetch a single row by PID.
     *
     * @return array<string, mixed>|null
     */
    public function fetchByPid(int $pid): ?array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT * FROM '.self::TABLE.' WHERE pid = :pid',
                ['pid' => $pid],
            );

            return false !== $row ? $row : null;
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to query background process.', 0, $e);
        }
    }

    /**
     * Fetch a single row by ID.
     *
     * @return array<string, mixed>|null
     */
    public function fetchById(int $id): ?array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT * FROM '.self::TABLE.' WHERE id = :id',
                ['id' => $id],
            );

            return false !== $row ? $row : null;
        } catch (DbalException $e) {
            return null;
        }
    }

    /**
     * Fetch all rows, optionally filtered by session.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(?string $sessionId = null): array
    {
        try {
            if (null !== $sessionId) {
                return $this->connection->fetchAllAssociative(
                    'SELECT * FROM '.self::TABLE.' WHERE session_id = :session_id ORDER BY id DESC',
                    ['session_id' => $sessionId],
                );
            }

            return $this->connection->fetchAllAssociative(
                'SELECT * FROM '.self::TABLE.' ORDER BY id DESC',
            );
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to list background processes.', 0, $e);
        }
    }

    /**
     * Fetch all unfinished rows (finished_at IS NULL), optionally scoped by session.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAllUnfinished(?string $sessionId = null): array
    {
        try {
            if (null !== $sessionId) {
                return $this->connection->fetchAllAssociative(
                    'SELECT * FROM '.self::TABLE.' WHERE finished_at IS NULL AND session_id = :session_id',
                    ['session_id' => $sessionId],
                );
            }

            return $this->connection->fetchAllAssociative(
                'SELECT * FROM '.self::TABLE.' WHERE finished_at IS NULL',
            );
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to query running background processes.', 0, $e);
        }
    }

    /**
     * Fetch all active (unfinished) PIDs.
     *
     * @return list<int>
     */
    public function fetchAllActivePids(): array
    {
        try {
            return $this->connection->fetchFirstColumn(
                'SELECT pid FROM '.self::TABLE.' WHERE finished_at IS NULL',
            );
        } catch (DbalException $e) {
            $this->logger->warning('background_process.fetch_active_pids_failed', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.fetch_active_pids_failed',
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Delete rows where finished_at is before the given cutoff.
     *
     * @return int Number of deleted rows
     */
    public function deleteOlderThan(string $cutoff): int
    {
        try {
            return $this->connection->delete(self::TABLE, [
                'finished_at<=' => $cutoff,
            ]);
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to delete stale background process records.', 0, $e);
        }
    }

    /**
     * Delete a single row by ID.
     */
    public function deleteById(int $id): void
    {
        try {
            $this->connection->executeStatement(
                'DELETE FROM '.self::TABLE.' WHERE id = :id',
                ['id' => $id],
            );
        } catch (DbalException $e) {
            $this->logger->warning('background_process.delete_failed', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.delete_failed',
                'record_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convert a raw DB row array into a typed BackgroundProcessRecord.
     *
     * The caller must inject the 'status' key into $row before calling
     * this method — it is not a real DB column.
     *
     * @param array<string, mixed> $row Enriched row (must include 'status' key)
     */
    public function normalizeRow(array $row): BackgroundProcessRecord
    {
        return $this->denormalizer->denormalize($row, BackgroundProcessRecord::class);
    }
}
