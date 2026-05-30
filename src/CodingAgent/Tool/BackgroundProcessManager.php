<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Psr\Log\LoggerInterface;

/**
 * Durable, DBAL-backed manager for background processes.
 *
 * Provides the production APIs that TOOLS-09 (bash foreground/background)
 * will call to start and register background processes. Exposes lifecycle
 * operations: start, list, log tail, stop (TERM → grace → KILL), stale
 * cleanup, and shutdown cleanup.
 *
 * Process lifecycle:
 *  1. start() creates a tracking record in the SQLite table and launches
 *     the command in a new session/process group via setsid. A shell
 *     wrapper backgrounds the command as a distinct child process,
 *     redirects stdout/stderr to a log file, and records exit status
 *     to a status file on completion. The wrapper traps SIGTERM and
 *     forwards it to the child so TERM reaches the actual workload.
 *  2. The process runs independently — the PHP tool worker exits while
 *     the child continues in its own session.
 *  3. On subsequent list() calls, refreshStatus() checks liveness via
 *     /proc/<pid> on Linux or the status file.
 *  4. stop() sends SIGTERM to the process group (negative PGID), waits
 *     the configured grace period, sends SIGKILL if still alive.
 *  5. cleanupStale() removes DB records and log files older than
 *     retention once the process has finished.
 *
 * Session ownership: every process stores an optional session_id.
 * Methods that accept ?string $sessionId will scope operations to
 * that session when provided; null means unscoped/admin (show all,
 * operate on any process regardless of session).
 * BgStatusTool resolves the current session from the ambient
 * StackToolExecutionContextAccessor so operations are scoped.
 *
 * Shutdown handling:
 * Call registerShutdownHandler() from production wiring (services.yaml)
 * to register a PHP shutdown function that calls shutdownCleanup() with
 * no session filter, killing ALL running background processes when this
 * PHP process exits. This covers graceful exit (Ctrl+C, quit command,
 * normal script end) and fatal errors.
 *
 * Crash resilience: SIGKILL, OOM, or segfault bypass the shutdown
 * function, so background processes survive unexpected controller
 * death. The user can inspect logs on the next session resume.
 *
 * setsid is required. If setsid is unavailable, start() fails with a
 * clear exception rather than falling back to unsafe single-PID mode
 * that cannot reliably propagate signals to child workloads.
 */
final class BackgroundProcessManager
{
    private const string TABLE_NAME = 'background_process';

    private bool $tableInitialized = false;
    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly BackgroundProcessConfig $config,
        private readonly ?LoggerInterface $logger = null,
    ) {
        // Shutdown handler is NOT registered here. Call
        // registerShutdownHandler() from production wiring
        // (config/services.yaml) to enable automatic cleanup.
        // This avoids accumulating shutdown callbacks in tests
        // where many manager instances are constructed.
    }

    /**
     * Register a PHP shutdown function that terminates all running
     * background processes when this PHP process exits.
     *
     * On graceful shutdown (exit, Ctrl+C, fatal error), this calls
     * shutdownCleanup() which TERM→grace→KILLs all tracked processes.
     * On hard crash (SIGKILL, OOM, segfault), the shutdown function
     * does not fire — BG processes survive for inspection or resume.
     *
     * Safe to call multiple times — idempotent.
     */
    public function registerShutdownHandler(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;

        register_shutdown_function(function (): void {
            $this->shutdownCleanup();
        });
    }

    // ─── Public lifecycle API ─────────────────────────────────────────

    /**
     * Start a background process and register it in the durable store.
     *
     * The command is launched in a new session/process group via setsid
     * and wrapped in a shell harness that:
     *  - Records the wrapper PID to a .pid file
     *  - Redirects stdout/stderr to a .log file
     *  - Backgrounds the user command as a distinct child process so it
     *    inherits default SIGTERM handling (not SIG_IGN)
     *  - Traps SIGTERM and forwards it to the child process, then waits
     *    for the child and writes its exit code to a .status file
     *  - Also writes the child exit code after normal completion
     *
     * Forwarding TERM via a trap ensures $command receives a deliverable
     * signal with default disposition, while the wrapper survives long
     * enough to write the status sidecar.  When stop() sends SIGKILL
     * (after grace expires) the wrapper dies immediately and the status
     * file is not written; refreshStatus() marks that scenario as
     * "finished (unclean)".
     *
     * setsid is required.  If setsid is not available or fails, this
     * method throws rather than falling back to single-PID mode that
     * cannot safely propagate TERM to child workloads.
     *
     * @param string      $command   The shell command to run in background.
     *                               Must already be shell-escaped if it contains
     *                               user-controlled tokens.
     * @param string|null $sessionId Optional session/run identifier for
     *                               ownership scoping. When provided, the
     *                               process is bound to this session.
     *                               Pass null for unscoped operation
     *                               (default, backwards-compat mode).
     *
     * @warning This method accepts raw shell commands and executes them via
     *          bash -c. Callers MUST escape all user-controlled tokens with
     *          escapeshellarg() before passing to start(). Do not pass
     *          unsanitized input or arguments as a flat string.
     *
     * @return array{id: int, pid: int, pgid: int|null, command: string,
     *               log_path: string, started_at: string,
     *               session_id: string, status: string}
     *
     * @throws \RuntimeException on storage/launch failure
     */
    public function start(string $command, ?string $sessionId = null): array
    {
        $this->ensureTable();

        // Ensure storage directory exists
        $bgDir = $this->ensureStorageDir();

        // Generate unique file prefix and file paths
        $filePrefix = bin2hex(random_bytes(8));
        $pidFile = $bgDir.'/'.$filePrefix.'.pid';
        $statusFile = $bgDir.'/'.$filePrefix.'.status';
        $logFile = $bgDir.'/'.$filePrefix.'.log';

        $now = $this->nowIso();

        // Preflight: verify setsid is available before attempting launch.
        // The shell pipeline "setsid … & echo $!" cannot detect setsid
        // failure because the shell exit code is from echo $!, not setsid.
        exec('command -v setsid', $_, $rc);
        if (0 !== $rc) {
            throw new \RuntimeException('setsid is required but not found on this platform.');
        }

        // Build a shell wrapper that:
        //  1. Records wrapper PID to pidFile
        //  2. Redirects stdout/stderr to logFile
        //  3. Backgrounds the user command as a child process (&) so
        //     the child inherits default SIGTERM (not ignored)
        //  4. Sets a SIGTERM trap in the wrapper that forwards TERM
        //     to the child, waits for it, writes the status, and exits
        //  5. Waits for the child on the normal path, stores child RC
        //     in a variable before echo so exit reflects child outcome
        //
        // Result is executed via: setsid bash -c '...' & echo $!
        $shellCode = \sprintf(
            'echo $$ > %s; exec >> %s 2>&1; %s & CHILD_PID=$!; STATUS_FILE=%s; trap \'kill -TERM $CHILD_PID 2>/dev/null; wait $CHILD_PID 2>/dev/null; echo $? > $STATUS_FILE; exit\' TERM; wait $CHILD_PID 2>/dev/null; RC=$?; echo $RC > $STATUS_FILE; exit $RC',
            escapeshellarg($pidFile),
            escapeshellarg($logFile),
            $command,
            escapeshellarg($statusFile),
        );

        // Launch with setsid (new process group) and capture PID via $!
        $launcher = 'setsid bash -c '.escapeshellarg($shellCode).' & echo $!';

        $output = [];
        $exitCode = -1;
        exec($launcher, $output, $exitCode);

        // Should not happen after preflight, but guard anyway
        if (0 !== $exitCode || [] === $output) {
            throw new \RuntimeException('Failed to launch background process: setsid returned exit code '.$exitCode.'. (pid: '.$pidFile.')');
        }

        $pid = (int) $output[0];
        if ($pid <= 0) {
            throw new \RuntimeException('Failed to launch background process: invalid PID ('.$output[0].').');
        }

        // Resolve PGID for group signalling
        $pgid = $this->resolvePgid($pid);

        // Insert record into DB
        try {
            $this->connection->executeStatement(
                \sprintf(
                    'INSERT INTO %s (pid, pgid, session_id, command, log_path, status_path, started_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    self::TABLE_NAME,
                ),
                [$pid, $pgid, $sessionId ?? '', $command, $logFile, $statusFile, $now, $now],
            );

            $dbId = (int) $this->connection->lastInsertId();
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to insert background process record.', 0, $e);
        }

        $resolvedSessionId = $sessionId ?? '';

        $this->logger?->info('background_process.started', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.started',
            'process_pid' => $pid,
            'process_pgid' => $pgid,
            'has_group_termination' => true,
            'log_path' => $logFile,
            'process_session_id' => $resolvedSessionId,
        ]);

        return [
            'id' => $dbId,
            'pid' => $pid,
            'pgid' => $pgid,
            'command' => $command,
            'log_path' => $logFile,
            'started_at' => $now,
            'session_id' => $resolvedSessionId,
            'status' => 'running',
        ];
    }

    /**
     * List all tracked background processes with refreshed status.
     *
     * @param string|null $sessionId Optional session filter. When provided,
     *                               only processes for that session are
     *                               returned. Pass null for all processes
     *                               (default, unscoped mode).
     *
     * @return list<array{id: int, pid: int, pgid: int|null, command: string,
     *                    log_path: string, started_at: string,
     *                    finished_at: string|null, exit_code: int|null,
     *                    stopped_by_user: bool, session_id: string,
     *                    status: string}>
     */
    public function list(?string $sessionId = null): array
    {
        $this->ensureTable();

        try {
            if (null !== $sessionId) {
                $rows = $this->connection->fetchAllAssociative(
                    \sprintf('SELECT * FROM %s WHERE session_id = ? ORDER BY id DESC', self::TABLE_NAME),
                    [$sessionId],
                );
            } else {
                $rows = $this->connection->fetchAllAssociative(
                    \sprintf('SELECT * FROM %s ORDER BY id DESC', self::TABLE_NAME),
                );
            }
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to list background processes.', 0, $e);
        }

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->normalizeRow($row);
        }

        return $results;
    }

    /**
     * Return the tail of a background process log file.
     *
     * Uses a shell command (tail -c) to read the last N bytes, avoiding
     * loading large files into PHP memory.
     *
     * @param int         $pid       Process PID (also the DB lookup key)
     * @param int|null    $maxChars  Maximum characters to return (null uses config default)
     * @param string|null $sessionId Optional session ownership check. When
     *                               provided, only a process belonging to
     *                               this session will be returned.
     *
     * @return array{pid: int, log_path: string, content: string,
     *               truncated: bool, total_bytes: int}
     *
     * @throws \RuntimeException when the process is not found,
     *                           session mismatch, or log is unreadable
     */
    public function readLogTail(int $pid, ?int $maxChars = null, ?string $sessionId = null): array
    {
        $maxChars ??= $this->config->logTailChars;

        $this->ensureTable();

        try {
            $row = $this->connection->fetchAssociative(
                \sprintf('SELECT log_path, session_id FROM %s WHERE pid = ?', self::TABLE_NAME),
                [$pid],
            );
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to query background process log path.', 0, $e);
        }

        if (false === $row || !\is_string($row['log_path'] ?? null)) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d.', $pid));
        }

        // Session ownership check
        if (null !== $sessionId && ($row['session_id'] ?? '') !== $sessionId) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d for this session.', $pid));
        }

        /** @var string $logPath */
        $logPath = $row['log_path'];

        if (!is_file($logPath) || !is_readable($logPath)) {
            return [
                'pid' => $pid,
                'log_path' => $logPath,
                'content' => '(log file not found or not readable)',
                'truncated' => false,
                'total_bytes' => 0,
            ];
        }

        $totalBytes = @filesize($logPath);
        if (false === $totalBytes) {
            $totalBytes = 0;
        }

        if ($totalBytes <= $maxChars) {
            /** @var string|false $content */
            $content = @file_get_contents($logPath);

            return [
                'pid' => $pid,
                'log_path' => $logPath,
                'content' => \is_string($content) ? $content : '(failed to read log)',
                'truncated' => false,
                'total_bytes' => $totalBytes,
            ];
        }

        // Read tail via shell for large files
        $tailCmd = \sprintf('tail -c %d %s 2>/dev/null', $maxChars, escapeshellarg($logPath));
        $content = @shell_exec($tailCmd);

        return [
            'pid' => $pid,
            'log_path' => $logPath,
            'content' => \is_string($content) ? $content : '(failed to read log)',
            'truncated' => true,
            'total_bytes' => $totalBytes,
        ];
    }

    /**
     * Stop a background process: TERM → grace → KILL.
     *
     * Targets the entire process group via negative PGID
     * (kill -TERM -<PGID>) when a PGID is available.  Falls back
     * to single-PID signalling only when resolvePgid() could not
     * determine the group — a rare race window immediately after
     * process launch.
     *
     * @param int         $pid       Process PID to stop. Must be > 0.
     * @param string|null $sessionId Optional session ownership check.
     *                               When provided, only a process belonging
     *                               to this session will be stopped.
     *
     * @return array{pid: int, pgid: int|null, stopped_by_user: bool,
     *               already_finished: bool, signal_sent: string}
     *
     * @throws \RuntimeException when the process is not found, session
     *                           mismatch, or PID is invalid
     */
    public function stop(int $pid, ?string $sessionId = null): array
    {
        $this->ensureTable();

        // Defence-in-depth: reject non-positive PIDs that could cause
        // kill(0) or kill(-negative) to broadcast signals to the caller.
        if ($pid <= 0) {
            throw new \RuntimeException(\sprintf('Invalid PID %d for stop.', $pid));
        }

        try {
            $row = $this->connection->fetchAssociative(
                \sprintf('SELECT * FROM %s WHERE pid = ?', self::TABLE_NAME),
                [$pid],
            );
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to query background process for stop.', 0, $e);
        }

        if (false === $row) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d.', $pid));
        }

        // Session ownership check
        if (null !== $sessionId && ($row['session_id'] ?? '') !== $sessionId) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d for this session.', $pid));
        }

        // Refresh status before acting — the process may have finished
        // since the last list() call (status file written, /proc gone).
        $idVal = $row['id'] ?? 0;
        $this->refreshRecord(is_numeric($idVal) ? (int) $idVal : 0);

        // Re-fetch after refresh
        try {
            $row = $this->connection->fetchAssociative(
                \sprintf('SELECT * FROM %s WHERE pid = ?', self::TABLE_NAME),
                [$pid],
            );
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to query background process after refresh.', 0, $e);
        }

        if (false === $row) {
            // Record was removed during refresh (unlikely but handle gracefully)
            throw new \RuntimeException(\sprintf('Background process with PID %d disappeared during refresh.', $pid));
        }

        // Session ownership check on re-fetched row
        if (null !== $sessionId && ($row['session_id'] ?? '') !== $sessionId) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d for this session.', $pid));
        }

        // Check if already finished (now correctly reflecting refreshed state)
        if (null !== ($row['finished_at'] ?? null)) {
            $pgidRaw = $row['pgid'] ?? null;

            return [
                'pid' => $pid,
                'pgid' => null !== $pgidRaw && (\is_int($pgidRaw) || (\is_string($pgidRaw) && ctype_digit($pgidRaw))) ? (int) $pgidRaw : null,
                'stopped_by_user' => false,
                'already_finished' => true,
                'signal_sent' => 'none',
            ];
        }

        $pgidRaw = $row['pgid'] ?? null;
        $pgid = null !== $pgidRaw && (\is_int($pgidRaw) || (\is_string($pgidRaw) && ctype_digit($pgidRaw))) ? (int) $pgidRaw : null;
        $graceSeconds = $this->config->stopGraceSeconds;

        // TERM signal — target process group (negative PGID)
        $signalSent = 'term';
        if (null !== $pgid && $pgid > 0) {
            @exec(\sprintf('kill -TERM -%d 2>/dev/null', $pgid));
        } else {
            @exec(\sprintf('kill -TERM %d 2>/dev/null', $pid));
        }

        // Wait grace period
        if ($graceSeconds > 0) {
            sleep($graceSeconds);
        }

        // Check if still alive and KILL if needed
        if ($this->isAlive($pid)) {
            $signalSent = 'term+kill';
            if (null !== $pgid && $pgid > 0) {
                @exec(\sprintf('kill -KILL -%d 2>/dev/null', $pgid));
            } else {
                @exec(\sprintf('kill -KILL %d 2>/dev/null', $pid));
            }
        }

        $this->logger?->info('background_process.stopped', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.stopped',
            'process_pid' => $pid,
            'process_pgid' => $pgid,
            'signal_sent' => $signalSent,
            'grace_seconds' => $graceSeconds,
        ]);

        // Mark as stopped by user
        $now = $this->nowIso();
        try {
            $this->connection->executeStatement(
                \sprintf(
                    'UPDATE %s SET stopped_by_user = 1, finished_at = ?, updated_at = ? WHERE pid = ?',
                    self::TABLE_NAME,
                ),
                [$now, $now, $pid],
            );
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to update background process stop record.', 0, $e);
        }

        // Write -1 to the status file only when the wrapper did not
        // already write a real exit code (KILL path).  When TERM succeeds
        // the wrapper's trap handler writes the child's real exit code
        // before exiting; overwriting would corrupt forensic evidence.
        $statusPath = $row['status_path'] ?? null;
        if (\is_string($statusPath) && '' !== $statusPath && !is_file($statusPath)) {
            @file_put_contents($statusPath, (string) (-1));
        }

        return [
            'pid' => $pid,
            'pgid' => $pgid,
            'stopped_by_user' => true,
            'already_finished' => false,
            'signal_sent' => $signalSent,
        ];
    }

    /**
     * Remove stale (finished) records and log files older than retention.
     *
     * Operates on processes where finished_at is set and
     * finished_at + retention < now.
     *
     * @return int Number of cleaned records
     */
    public function cleanupStale(): int
    {
        $this->ensureTable();

        // First, refresh all unfinished records so finished_at is populated
        // for processes that completed without a list() call.
        $this->refreshAllUnfinished();

        $cutoff = date('c', time() - $this->config->retentionSeconds);

        try {
            $stale = $this->connection->fetchAllAssociative(
                \sprintf(
                    'SELECT id, log_path, status_path FROM %s
                     WHERE finished_at IS NOT NULL AND finished_at <= ?',
                    self::TABLE_NAME,
                ),
                [$cutoff],
            );
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to query stale background processes.', 0, $e);
        }

        $count = 0;
        foreach ($stale as $row) {
            // Delete log and status files
            if (isset($row['log_path']) && \is_string($row['log_path']) && is_file($row['log_path'])) {
                @unlink($row['log_path']);
            }
            if (isset($row['status_path']) && \is_string($row['status_path']) && is_file($row['status_path'])) {
                @unlink($row['status_path']);
            }

            // Delete DB record
            try {
                $this->connection->executeStatement(
                    \sprintf('DELETE FROM %s WHERE id = ?', self::TABLE_NAME),
                    [(int) $row['id']],
                );
                ++$count;
            } catch (DbalException $e) {
                // Log and continue — one failure should not block the rest of cleanup
                $this->logger?->warning('background_process.cleanup_failed', [
                    'component' => 'tool.background_process',
                    'event_type' => 'background_process.cleanup_failed',
                    'record_id' => (int) $row['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Also clean up orphaned .pid files (from crashed processes)
        $this->cleanupOrphanedPidFiles();

        return $count;
    }

    /**
     * Terminate all currently tracked running processes.
     *
     * Called automatically via register_shutdown_function() on PHP process
     * exit (graceful or fatal). Also callable explicitly from test tearDown
     * or controller lifecycle hooks. Safe to call multiple times — only
     * processes still marked running are affected.
     *
     * @param string|null $sessionId Optional session filter. When provided,
     *                               only processes for that session are
     *                               stopped. Pass null to stop all running
     *                               processes (default, unscoped).
     *
     * @return int Number of processes terminated
     */
    public function shutdownCleanup(?string $sessionId = null): int
    {
        $this->ensureTable();

        try {
            if (null !== $sessionId) {
                $running = $this->connection->fetchAllAssociative(
                    \sprintf('SELECT pid FROM %s WHERE finished_at IS NULL AND session_id = ?', self::TABLE_NAME),
                    [$sessionId],
                );
            } else {
                $running = $this->connection->fetchAllAssociative(
                    \sprintf('SELECT pid FROM %s WHERE finished_at IS NULL', self::TABLE_NAME),
                );
            }
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to query running background processes for shutdown.', 0, $e);
        }

        $count = 0;
        foreach ($running as $row) {
            $pidVal = $row['pid'] ?? 0;
            $pid = is_numeric($pidVal) ? (int) $pidVal : 0;
            try {
                $this->stop($pid);
                ++$count;
            } catch (\RuntimeException $e) {
                // Process may have exited between fetch and stop; log and continue
                $this->logger?->warning('background_process.shutdown_cleanup_error', [
                    'component' => 'tool.background_process',
                    'event_type' => 'background_process.shutdown_cleanup_error',
                    'process_pid' => $pid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    // ─── Status refresh helpers (used by list, stop, cleanup) ────────

    /**
     * Refresh a single record by ID, checking filesystem state and
     * updating the DB row if the process has finished.
     */
    private function refreshRecord(int $id): void
    {
        try {
            /** @var array<string, mixed>|false $row */
            $row = $this->connection->fetchAssociative(
                \sprintf('SELECT * FROM %s WHERE id = ?', self::TABLE_NAME),
                [$id],
            );
        } catch (DbalException $e) {
            $this->logger?->warning('background_process.refresh_failed', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.refresh_failed',
                'error' => $e->getMessage(),
            ]);

            return; // intentional degradation: one record refresh failing should not block others
        }

        if (false === $row) {
            return;
        }

        $this->refreshStatus($row);
    }

    /**
     * Refresh all unfinished records so finished_at is populated
     * for processes that completed without a list() call.
     */
    private function refreshAllUnfinished(): void
    {
        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->connection->fetchAllAssociative(
                \sprintf('SELECT * FROM %s WHERE finished_at IS NULL', self::TABLE_NAME),
            );
        } catch (DbalException $e) {
            $this->logger?->warning('background_process.refresh_all_failed', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.refresh_all_failed',
                'error' => $e->getMessage(),
            ]);

            return; // intentional degradation: unable to refresh, cleanup will skip
        }

        foreach ($rows as $row) {
            $this->refreshStatus($row);
        }
    }

    // ─── Internal helpers ────────────────────────────────────────────

    /**
     * Refresh the status of a single row by examining the filesystem.
     *
     * This is called by normalizeRow() and updates the DB if the process
     * has finished since the last check.
     *
     * @param array<string, mixed> $row
     *
     * @return string 'running' | 'finished' | 'stopped' | 'unknown'
     */
    private function refreshStatus(array &$row): string
    {
        // Already finished
        if (null !== ($row['finished_at'] ?? null)) {
            $stopped = (bool) ($row['stopped_by_user'] ?? false);

            return $stopped ? 'stopped' : 'finished';
        }

        $pidVal = $row['pid'] ?? 0;
        $pid = is_numeric($pidVal) ? (int) $pidVal : 0;
        $statusPath = $row['status_path'] ?? null;

        // Check status file first (process might have finished)
        if (\is_string($statusPath) && '' !== $statusPath && is_file($statusPath)) {
            $exitCodeRaw = @file_get_contents($statusPath);
            if (\is_string($exitCodeRaw) && '' !== trim($exitCodeRaw)) {
                $exitCode = (int) trim($exitCodeRaw);
                $now = $this->nowIso();

                $row['finished_at'] = $now;
                $row['exit_code'] = $exitCode;

                try {
                    $this->connection->executeStatement(
                        \sprintf(
                            'UPDATE %s SET finished_at = ?, exit_code = ?, updated_at = ? WHERE id = ?',
                            self::TABLE_NAME,
                        ),
                        [$now, $exitCode, $now, (int) $row['id']],
                    );
                } catch (DbalException $e) {
                    throw new \RuntimeException('Failed to update background process finished status.', 0, $e);
                }

                return 0 === $exitCode ? 'finished' : 'finished (exit code '.$exitCode.')';
            }
        }

        // Check /proc/<pid> for liveness
        if ($this->isAlive($pid)) {
            return 'running';
        }

        // Process is gone but no status file written (crash / SIGKILL / unclean exit)
        $now = $this->nowIso();
        $row['finished_at'] = $now;
        $row['exit_code'] = null;

        $this->logger?->info('background_process.finished_unclean', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.finished_unclean',
            'process_pid' => $pid,
        ]);

        try {
            $this->connection->executeStatement(
                \sprintf(
                    'UPDATE %s SET finished_at = ?, updated_at = ? WHERE id = ?',
                    self::TABLE_NAME,
                ),
                [$now, $now, (int) $row['id']],
            );
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to update background process terminated status.', 0, $e);
        }

        return 'finished (unclean)';
    }

    /**
     * Check if a process is alive by examining /proc/<pid>.
     *
     * No caching: every call performs a fresh /proc/<pid> check so that
     * stop() correctly detects TERM-induced termination after the grace
     * window rather than returning a stale cached result.
     */
    private function isAlive(int $pid): bool
    {
        // Use /proc/<pid> on Linux; fallback to kill -0
        if (is_dir('/proc/'.$pid)) {
            return true;
        }

        // Fallback: kill -0
        @exec('kill -0 '.$pid.' 2>/dev/null', $_, $exitCode);

        return 0 === $exitCode;
    }

    /**
     * Resolve the process group ID for a given PID.
     *
     * Retries briefly to close a startup race: after exec() returns
     * the PID, the process may not yet be visible to ps.
     */
    private function resolvePgid(int $pid): ?int
    {
        // Retry up to 5 times with 50ms backoff to close the startup
        // race where the PID exists but ps cannot see it yet.
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $pgidStr = @shell_exec(\sprintf('ps -o pgid= -p %d 2>/dev/null', $pid));
            if (\is_string($pgidStr) && '' !== trim($pgidStr)) {
                $pgid = (int) trim($pgidStr);
                if ($pgid > 0) {
                    return $pgid;
                }
            }
            if ($attempt < 4) {
                usleep(50_000);
            }
        }

        return null;
    }

    /**
     * Normalize a DB row into the list format with refreshed status.
     *
     * @param array<string, mixed> $row
     *
     * @return array{id: int, pid: int, pgid: int|null, command: string,
     *               log_path: string, started_at: string,
     *               finished_at: string|null, exit_code: int|null,
     *               stopped_by_user: bool, session_id: string,
     *               status: string}
     */
    private function normalizeRow(array $row): array
    {
        $status = $this->refreshStatus($row);

        $id = $row['id'] ?? 0;
        $pid = $row['pid'] ?? 0;
        $pgidRaw = $row['pgid'] ?? null;
        $sessionId = $row['session_id'] ?? '';
        $command = $row['command'] ?? '';
        $logPath = $row['log_path'] ?? '';
        $startedAt = $row['started_at'] ?? '';
        $finishedAt = $row['finished_at'] ?? null;
        $exitCode = $row['exit_code'] ?? null;
        $stoppedByUser = $row['stopped_by_user'] ?? false;

        return [
            'id' => \is_int($id) || (\is_string($id) && ctype_digit($id)) ? (int) $id : 0,
            'pid' => \is_int($pid) || (\is_string($pid) && ctype_digit($pid)) ? (int) $pid : 0,
            'pgid' => (null !== $pgidRaw && (\is_int($pgidRaw) || (\is_string($pgidRaw) && ctype_digit($pgidRaw)))) ? (int) $pgidRaw : null,
            'session_id' => \is_string($sessionId) ? $sessionId : (string) $sessionId,
            'command' => \is_string($command) ? $command : (string) $command,
            'log_path' => \is_string($logPath) ? $logPath : (string) $logPath,
            'started_at' => \is_string($startedAt) ? $startedAt : (string) $startedAt,
            'finished_at' => \is_string($finishedAt) ? $finishedAt : null,
            'exit_code' => null !== $exitCode ? (\is_int($exitCode) ? $exitCode : (int) $exitCode) : null,
            'stopped_by_user' => (bool) $stoppedByUser,
            'status' => $status,
        ];
    }

    /**
     * Ensure the storage directory exists and return its resolved path.
     */
    private function ensureStorageDir(): string
    {
        $bgDir = $this->config->storageDir;

        if (!is_dir($bgDir)) {
            $created = @mkdir($bgDir, 0750, recursive: true);
            if (!$created && !is_dir($bgDir)) {
                throw new \RuntimeException(\sprintf('Failed to create background process storage directory: %s', $bgDir));
            }
        }

        if (!is_writable($bgDir)) {
            throw new \RuntimeException(\sprintf('Background process storage directory is not writable: %s', $bgDir));
        }

        return $bgDir;
    }

    /**
     * Clean up orphaned .pid files left by crashed or improperly cleaned processes.
     */
    private function cleanupOrphanedPidFiles(): void
    {
        $bgDir = $this->config->storageDir;
        if (!is_dir($bgDir)) {
            return;
        }

        // Collect all active DB record PIDs
        try {
            $activePids = $this->connection->fetchFirstColumn(
                \sprintf('SELECT pid FROM %s WHERE finished_at IS NULL', self::TABLE_NAME),
            );
        } catch (DbalException $e) {
            $this->logger?->warning('background_process.cleanup_orphaned_failed', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.cleanup_orphaned_failed',
                'error' => $e->getMessage(),
            ]);

            return; // intentional degradation: orphaned PID cleanup is best-effort
        }

        /** @var array<int|string, true> $activePidSet */
        $activePidSet = [];
        foreach ($activePids as $activePid) {
            if (\is_int($activePid) || \is_string($activePid)) {
                $activePidSet[(string) $activePid] = true;
            }
        }

        $iterator = new \FilesystemIterator($bgDir, \FilesystemIterator::SKIP_DOTS);
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $filename = $file->getFilename();

            // Only clean .pid files
            if (!str_ends_with($filename, '.pid')) {
                continue;
            }

            $pidContent = @file_get_contents($file->getPathname());
            $pidFromFile = \is_string($pidContent) ? (int) trim($pidContent) : 0;
            if ($pidFromFile > 0 && !isset($activePidSet[$pidFromFile])) {
                // This PID is not in our active set — the .pid file is orphaned
                @unlink($file->getPathname());

                // Also clean up companion .status and .log files if they exist
                $base = $file->getPath().'/'.pathinfo($filename, \PATHINFO_FILENAME);
                foreach (['.status', '.log'] as $ext) {
                    $companion = $base.$ext;
                    if (is_file($companion)) {
                        @unlink($companion);
                    }
                }
            }
        }
    }

    /**
     * Ensure the background_process table exists.
     */
    private function ensureTable(): void
    {
        if ($this->tableInitialized) {
            return;
        }

        try {
            $this->connection->executeStatement(\sprintf(
                'CREATE TABLE IF NOT EXISTS %s (
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
                )',
                self::TABLE_NAME,
            ));

            // Add session_id column if table already existed without it
            // (SQLite migration: ALTER TABLE ADD COLUMN is a no-op if
            //  the column already exists, but we wrap in try/catch for
            //  safety, as some versions may error on duplicate column.)
            try {
                $this->connection->executeStatement(
                    \sprintf('ALTER TABLE %s ADD COLUMN session_id TEXT NOT NULL DEFAULT \'\'', self::TABLE_NAME),
                );
            } catch (DbalException $e) {
                $this->logger?->debug('background_process migration: column likely already exists', [
                    'exception' => $e->getMessage(),
                ]);
            }

            $this->tableInitialized = true;
        } catch (DbalException $e) {
            throw new \RuntimeException('Failed to create background_process table.', 0, $e);
        }
    }

    private function nowIso(): string
    {
        return date('c');
    }
}
