<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;

/**
 * Doctrine DBAL-backed durable batch store using the shared messenger SQLite.
 *
 * Stores per-run/per-turn/per-step tool batch execution state in a
 * `tool_batch_state` table. Uses JSON serialization for the batch
 * state array so it can be reconstructed by any consumer process.
 *
 * Table schema:
 *
 *   run_id       TEXT NOT NULL,
 *   turn_no      INTEGER NOT NULL,
 *   step_id      TEXT NOT NULL,
 *   batch_data   TEXT NOT NULL,       -- JSON-serialized batch state
 *   created_at   TEXT NOT NULL DEFAULT (datetime('now')),
 *   updated_at   TEXT NOT NULL DEFAULT (datetime('now')),
 *   PRIMARY KEY (run_id, turn_no, step_id)
 *
 * The table is created lazily on first use (CREATE TABLE IF NOT EXISTS)
 * so no explicit migration step is needed.
 */
final class DbalToolBatchStore implements ToolBatchStoreInterface
{
    private const string TABLE_NAME = 'tool_batch_state';

    private bool $tableInitialized = false;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function load(string $runId, int $turnNo, string $stepId): ?array
    {
        $this->ensureTable();

        try {
            $row = $this->connection->fetchAssociative(
                \sprintf('SELECT batch_data FROM %s WHERE run_id = ? AND turn_no = ? AND step_id = ?', self::TABLE_NAME),
                [$runId, $turnNo, $stepId],
            );
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to load tool batch state from DBAL store.', 0, $e);
        }

        if (false === $row || !\is_array($row) || !\is_string($row['batch_data'] ?? null)) {
            return null;
        }

        $decoded = json_decode($row['batch_data'], true);

        if (!\is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    public function save(string $runId, int $turnNo, string $stepId, array $batchState): void
    {
        $this->ensureTable();

        $json = json_encode($batchState, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        try {
            $this->connection->executeStatement(
                \sprintf(
                    'INSERT INTO %s (run_id, turn_no, step_id, batch_data) VALUES (?, ?, ?, ?)
                     ON CONFLICT(run_id, turn_no, step_id) DO UPDATE SET batch_data = ?, updated_at = datetime(\'now\')',
                    self::TABLE_NAME,
                ),
                [$runId, $turnNo, $stepId, $json, $json],
            );
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to save tool batch state to DBAL store.', 0, $e);
        }
    }

    public function delete(string $runId, int $turnNo, string $stepId): void
    {
        $this->ensureTable();

        try {
            $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE run_id = ? AND turn_no = ? AND step_id = ?', self::TABLE_NAME),
                [$runId, $turnNo, $stepId],
            );
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to delete tool batch state from DBAL store.', 0, $e);
        }
    }

    /**
     * Ensure the tool_batch_state table exists.
     *
     * Creates the table lazily on first store operation so no explicit
     * migration is needed — the table appears in the shared messenger
     * SQLite database on first tool execution.
     */
    private function ensureTable(): void
    {
        if ($this->tableInitialized) {
            return;
        }

        try {
            $this->connection->executeStatement(\sprintf(
                'CREATE TABLE IF NOT EXISTS %s (
                    run_id       TEXT NOT NULL,
                    turn_no      INTEGER NOT NULL,
                    step_id      TEXT NOT NULL,
                    batch_data   TEXT NOT NULL,
                    created_at   TEXT NOT NULL DEFAULT (datetime(\'now\')),
                    updated_at   TEXT NOT NULL DEFAULT (datetime(\'now\')),
                    PRIMARY KEY (run_id, turn_no, step_id)
                )',
                self::TABLE_NAME,
            ));

            $this->tableInitialized = true;
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to create tool_batch_state table.', 0, $e);
        }
    }
}
