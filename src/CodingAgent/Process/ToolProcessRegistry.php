<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Process;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Table;

/**
 * Cross-process tool process registry backed by a shared SQLite table.
 *
 * WHY A REGISTRY IS NEEDED
 * =========================
 * The Controller process and Messenger tool consumers are separate OS
 * processes. When a cancel command arrives in the Controller, it must
 * terminate the foreground tool subprocesses owned by the tool workers.
 * This registry bridges the two sides:
 *
 *   Tool worker: starts a subprocess, registers PID/PGID in the registry.
 *   Controller:  receives cancel, reads registry for the run, kills PIDs.
 *
 * Each record maps runId+toolCallId → pid/pgid for direct or process-group
 * termination. Records are created by ForegroundProcessRunner and cleaned
 * up when the tool completes or the run is cancelled.
 *
 * TECHNOLOGY CHOICE: SQLite via Doctrine DBAL
 * ============================================
 * The registry uses the project's existing SQLite database
 * (%app.cwd%/.hatfield/messenger.sqlite — the same DB used by Messenger
 * transport queues). A dedicated hatfield_tool_processes table stores
 * process records. SQLite provides transactional CRUD with row-level
 * consistency, eliminating the TOCTOU races and manual file-lock
 * complexity of the previous JSONL+flock approach.
 *
 * TABLE SCHEMA
 * ============
 * ```sql
 * CREATE TABLE IF NOT EXISTS hatfield_tool_processes (
 *     process_id       TEXT PRIMARY KEY,   -- "{runId}:{toolCallId}"
 *     run_id           TEXT NOT NULL,
 *     turn_no          INTEGER NOT NULL,
 *     tool_call_id     TEXT NOT NULL,
 *     kind             TEXT NOT NULL,       -- 'foreground_tool' | 'background_tool'
 *     pid              INTEGER NOT NULL,
 *     pgid             INTEGER,             -- nullable process-group ID
 *     command_preview  TEXT NOT NULL DEFAULT '',
 *     cwd              TEXT NOT NULL DEFAULT '',
 *     log_path         TEXT,                -- nullable log file path
 *     started_at       INTEGER NOT NULL,    -- unix timestamp
 *     updated_at       INTEGER NOT NULL     -- unix timestamp
 * );
 * CREATE INDEX IF NOT EXISTS idx_tp_run_kind ON hatfield_tool_processes(run_id, kind);
 * ```
 *
 * CLEANUP
 * =======
 * Records are cleaned up (unregister/removeRun/prune) via SQL DELETE
 * statements. Stale records (processes that exited without unregistering)
 * are pruned by age during registry initialization or on a schedule.
 *
 * @see ForegroundProcessRunner  creates and clears foreground records
 * @see CancelHandler            reads foreground records on cancel
 * @see ProcessTerminator        terminates actual OS processes
 */
final class ToolProcessRegistry
{
    private const string TABLE_NAME = 'hatfield_tool_processes';

    private bool $tableEnsured = false;

    private readonly Connection $connection;

    /**
     * @param Connection|null $connection Doctrine DBAL connection.
     *                                    Defaults to a project-local SQLite database
     *                                    at CWD/.hatfield/messenger.sqlite.
     */
    public function __construct(
        ?Connection $connection = null,
    ) {
        if (null !== $connection) {
            $this->connection = $connection;
        } else {
            $cwd = getcwd();
            $dbPath = (false !== $cwd ? $cwd : sys_get_temp_dir()).'/.hatfield/messenger.sqlite';
            $dir = \dirname($dbPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0o775, true);
            }

            $this->connection = DriverManager::getConnection(['url' => 'sqlite:///'.$dbPath]);
        }
    }

    /**
     * Register a process record (insert or update if already exists).
     */
    public function register(ToolProcessRecordDTO $record): void
    {
        $this->ensureTable();
        $now = time();

        $processId = $record->runId.':'.$record->toolCallId;

        $this->connection->executeStatement(
            \sprintf(
                'INSERT OR REPLACE INTO %s (process_id, run_id, turn_no, tool_call_id, kind, pid, pgid, command_preview, cwd, log_path, started_at, updated_at) '
                .'VALUES (:process_id, :run_id, :turn_no, :tool_call_id, :kind, :pid, :pgid, :command_preview, :cwd, :log_path, :started_at, :updated_at)',
                self::TABLE_NAME,
            ),
            [
                'process_id' => $processId,
                'run_id' => $record->runId,
                'turn_no' => $record->turnNo,
                'tool_call_id' => $record->toolCallId,
                'kind' => $record->kind->value,
                'pid' => $record->pid,
                'pgid' => $record->processGroupId,
                'command_preview' => $record->commandPreview,
                'cwd' => $record->cwd,
                'log_path' => $record->logPath,
                'started_at' => $record->startedAt?->getTimestamp() ?? $now,
                'updated_at' => $now,
            ],
            [
                'pgid' => ParameterType::INTEGER,
            ],
        );
    }

    /**
     * Remove a process record by run ID and tool call ID.
     */
    public function unregister(string $runId, string $toolCallId): void
    {
        $this->ensureTable();
        $processId = $runId.':'.$toolCallId;

        $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE process_id = :process_id', self::TABLE_NAME),
            ['process_id' => $processId],
        );
    }

    /**
     * Return all foreground tool records for a given run.
     *
     * @return list<ToolProcessRecordDTO>
     */
    public function foregroundForRun(string $runId): array
    {
        return $this->findByRunAndKind($runId, ToolProcessKindEnum::ForegroundTool);
    }

    /**
     * Return all background tool records for a given run.
     *
     * @return list<ToolProcessRecordDTO>
     */
    public function backgroundForRun(string $runId): array
    {
        return $this->findByRunAndKind($runId, ToolProcessKindEnum::BackgroundTool);
    }

    /**
     * Remove stale records older than the given threshold.
     *
     * @return int Number of records pruned
     */
    public function pruneOlderThan(\DateTimeImmutable $threshold): int
    {
        $this->ensureTable();
        $ts = $threshold->getTimestamp();

        return (int) $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE started_at < :threshold', self::TABLE_NAME),
            ['threshold' => $ts],
        );
    }

    /**
     * Remove all records for a given run.
     *
     * @return int Number of records removed
     */
    public function removeRun(string $runId): int
    {
        $this->ensureTable();

        return (int) $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE run_id = :run_id', self::TABLE_NAME),
            ['run_id' => $runId],
        );
    }

    /**
     * @return list<ToolProcessRecordDTO>
     */
    private function findByRunAndKind(string $runId, ToolProcessKindEnum $kind): array
    {
        $this->ensureTable();

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT * FROM %s WHERE run_id = :run_id AND kind = :kind ORDER BY started_at ASC',
                self::TABLE_NAME,
            ),
            ['run_id' => $runId, 'kind' => $kind->value],
        );

        return array_map(
            static fn (array $row): ToolProcessRecordDTO => new ToolProcessRecordDTO(
                runId: $row['run_id'],
                turnNo: (int) $row['turn_no'],
                toolCallId: $row['tool_call_id'],
                kind: ToolProcessKindEnum::from($row['kind']),
                pid: (int) $row['pid'],
                processGroupId: null !== $row['pgid'] ? (int) $row['pgid'] : null,
                commandPreview: (string) ($row['command_preview'] ?? ''),
                cwd: (string) ($row['cwd'] ?? ''),
                logPath: $row['log_path'],
                startedAt: $row['started_at'] ? new \DateTimeImmutable('@'.$row['started_at']) : null,
                updatedAt: $row['updated_at'] ? new \DateTimeImmutable('@'.$row['updated_at']) : null,
            ),
            $rows,
        );
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $schemaManager = $this->connection->createSchemaManager();
        $tables = $schemaManager->listTableNames();

        if (\in_array(self::TABLE_NAME, $tables, true)) {
            $this->tableEnsured = true;

            return;
        }

        $table = new Table(self::TABLE_NAME);
        $table->addColumn('process_id', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('run_id', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('turn_no', 'integer', ['notnull' => true]);
        $table->addColumn('tool_call_id', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('kind', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('pid', 'integer', ['notnull' => true]);
        $table->addColumn('pgid', 'integer', ['notnull' => false]);
        $table->addColumn('command_preview', 'string', ['length' => 255, 'notnull' => true, 'default' => '']);
        $table->addColumn('cwd', 'string', ['length' => 1024, 'notnull' => true, 'default' => '']);
        $table->addColumn('log_path', 'string', ['length' => 1024, 'notnull' => false]);
        $table->addColumn('started_at', 'integer', ['notnull' => true]);
        $table->addColumn('updated_at', 'integer', ['notnull' => true]);
        $table->setPrimaryKey(['process_id']);
        $table->addIndex(['run_id', 'kind'], 'idx_tp_run_kind');

        $schemaManager->createTable($table);
        $this->tableEnsured = true;
    }
}
