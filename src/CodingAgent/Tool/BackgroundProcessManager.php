<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Tool\BackgroundProcess\BackgroundProcessRecord;
use Ineersa\CodingAgent\Tool\BackgroundProcess\LogTailResult;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StartResult;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StopResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Durable, DBAL-backed manager for background processes.
 *
 * Provides the production APIs that TOOLS-09 (bash foreground/background)
 * will call to start and register background processes. Exposes lifecycle
 * operations: start, list, log tail, stop (TERM → grace → KILL), stale
 * cleanup, and shutdown cleanup.
 *
 * This is a thin facade that delegates database operations to ProcessStore
 * and OS/filesystem operations to ProcessLifecycle.
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
 *  3. On subsequent list() calls, normalizeRow() checks liveness via
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
 *
 * CamelCase property mapping is handled by the CamelCaseToSnakeCaseNameConverter
 * on the dedicated ObjectNormalizer, not the global @serializer. This isolation
 * prevents breaking other DTOs (e.g. RunState) that expect exact property name
 * matching.
 */
final class BackgroundProcessManager
{
    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly ProcessStore $store,
        private readonly ProcessLifecycle $lifecycle,
        private readonly BackgroundProcessConfig $config,
        private readonly LoggerInterface $logger,
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
     *
     * This does NOT conflict with Symfony Messenger workers:
     * BackgroundProcessManager is only constructed in the
     * controller/in-process agent paths, never inside
     * messenger:consume worker processes. Each agent process
     * registers exactly one shutdown function.
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
     * @throws \RuntimeException on storage/launch failure
     */
    public function start(string $command, ?string $sessionId = null): StartResult
    {
        $this->store->ensureTable();

        // Ensure storage directory exists
        $bgDir = $this->lifecycle->ensureStorageDir();

        // Generate unique file prefix and file paths
        $filePrefix = bin2hex(random_bytes(8));
        $pidFile = $bgDir.'/'.$filePrefix.'.pid';
        $statusFile = $bgDir.'/'.$filePrefix.'.status';
        $logFile = $bgDir.'/'.$filePrefix.'.log';

        $now = Clock::get()->now()->format('c');

        // Launch process in new session group
        $launchResult = $this->lifecycle->launchProcess($command, $pidFile, $logFile, $statusFile);
        $pid = $launchResult['pid'];
        $pgid = $launchResult['pgid'];

        // Insert record into DB
        $dbId = $this->store->insertRecord([
            'pid' => $pid,
            'pgid' => $pgid,
            'session_id' => $sessionId ?? '',
            'command' => $command,
            'log_path' => $logFile,
            'status_path' => $statusFile,
            'started_at' => $now,
            'updated_at' => $now,
        ]);

        $resolvedSessionId = $sessionId ?? '';

        $this->logger->info('background_process.started', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.started',
            'process_pid' => $pid,
            'process_pgid' => $pgid,
            'has_group_termination' => true,
            'log_path' => $logFile,
            'process_session_id' => $resolvedSessionId,
        ]);

        return new StartResult(
            id: $dbId,
            pid: $pid,
            pgid: $pgid,
            command: $command,
            logPath: $logFile,
            startedAt: $now,
            sessionId: $resolvedSessionId,
            status: 'running',
        );
    }

    /**
     * List all tracked background processes with refreshed status.
     *
     * @param string|null $sessionId Optional session filter. When provided,
     *                               only processes for that session are
     *                               returned. Pass null for all processes.
     *
     * @return list<BackgroundProcessRecord>
     */
    public function list(?string $sessionId = null): array
    {
        $this->store->ensureTable();

        $rows = $this->store->fetchAll($sessionId);

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->normalizeRow($row);
        }

        return $results;
    }

    /**
     * Return the tail of a background process log file.
     *
     * @param int         $pid       Process PID (also the DB lookup key)
     * @param int|null    $maxChars  Maximum characters to return (null uses config default)
     * @param string|null $sessionId Optional session ownership check. When
     *                               provided, only a process belonging to
     *                               this session will be returned.
     *
     * @throws \RuntimeException when the process is not found,
     *                           session mismatch, or log is unreadable
     */
    public function readLogTail(int $pid, ?int $maxChars = null, ?string $sessionId = null): LogTailResult
    {
        $maxChars ??= $this->config->logTailChars;

        $this->store->ensureTable();

        $row = $this->store->fetchByPid($pid);

        if (null === $row || !\is_string($row['log_path'] ?? null)) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d.', $pid));
        }

        // Session ownership check
        if (null !== $sessionId && ($row['session_id'] ?? '') !== $sessionId) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d for this session.', $pid));
        }

        /** @var string $logPath */
        $logPath = $row['log_path'];

        return $this->lifecycle->readLogTail($logPath, $pid, $maxChars);
    }

    /**
     * Stop a background process: TERM → grace → KILL.
     *
     * Targets the entire process group via negative PGID
     * (kill -TERM -<PGID>) when a PGID is available.  Falls back
     * to single-PID signalling only when the PGID could not be
     * determined — a rare race window immediately after process launch.
     *
     * @param int         $pid       Process PID to stop. Must be > 0.
     * @param string|null $sessionId Optional session ownership check.
     *                               When provided, only a process belonging
     *                               to this session will be stopped.
     *
     * @throws \RuntimeException when the process is not found, session
     *                           mismatch, or PID is invalid
     */
    public function stop(int $pid, ?string $sessionId = null): StopResult
    {
        $this->store->ensureTable();

        // Defence-in-depth: reject non-positive PIDs that could cause
        // kill(0) or kill(-negative) to broadcast signals to the caller.
        if ($pid <= 0) {
            throw new \RuntimeException(\sprintf('Invalid PID %d for stop.', $pid));
        }

        // Fetch row
        $row = $this->store->fetchByPid($pid);

        if (null === $row) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d.', $pid));
        }

        // Session ownership check
        if (null !== $sessionId && ($row['session_id'] ?? '') !== $sessionId) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d for this session.', $pid));
        }

        $idVal = $row['id'] ?? 0;
        $id = is_numeric($idVal) ? (int) $idVal : 0;

        // Refresh status before acting — the process may have finished
        // since the last list() call (status file written, /proc gone).
        $this->refreshSingleRecord($id);

        // Re-fetch after refresh
        $row = $this->store->fetchByPid($pid);

        if (null === $row) {
            throw new \RuntimeException(\sprintf('Background process with PID %d disappeared during refresh.', $pid));
        }

        // Session ownership check on re-fetched row
        if (null !== $sessionId && ($row['session_id'] ?? '') !== $sessionId) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d for this session.', $pid));
        }

        // Check if already finished (now correctly reflecting refreshed state)
        if (null !== ($row['finished_at'] ?? null)) {
            return new StopResult(
                pid: $pid,
                pgid: $this->lifecycle->coerceNullableInt($row['pgid'] ?? null),
                stoppedByUser: false,
                alreadyFinished: true,
                signalSent: 'none',
            );
        }

        $pgid = $this->lifecycle->coerceNullableInt($row['pgid'] ?? null);
        $graceSeconds = $this->config->stopGraceSeconds;

        // TERM signal — target process group (negative PGID)
        $signalSent = 'term';
        $this->lifecycle->sendTerm($pid, $pgid);

        // Wait grace period
        if ($graceSeconds > 0) {
            sleep($graceSeconds);
        }

        // Check if still alive and KILL if needed
        if ($this->lifecycle->isAlive($pid)) {
            $signalSent = 'term+kill';
            $this->lifecycle->sendKill($pid, $pgid);
        }

        $this->logger->info('background_process.stopped', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.stopped',
            'process_pid' => $pid,
            'process_pgid' => $pgid,
            'signal_sent' => $signalSent,
            'grace_seconds' => $graceSeconds,
        ]);

        // Mark as stopped by user
        $now = Clock::get()->now()->format('c');
        $this->store->markStoppedByUser($pid, $now);

        // Write -1 to the status file only when the wrapper did not
        // already write a real exit code (KILL path). When TERM succeeds
        // the wrapper's trap handler writes the child's real exit code
        // before exiting; overwriting would corrupt forensic evidence.
        $statusPath = $row['status_path'] ?? null;
        if (\is_string($statusPath)) {
            $this->lifecycle->writeStopMarker($statusPath);
        }

        return new StopResult(
            pid: $pid,
            pgid: $pgid,
            stoppedByUser: true,
            alreadyFinished: false,
            signalSent: $signalSent,
        );
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
        $this->store->ensureTable();

        // First, refresh all unfinished records so finished_at is populated
        // for processes that completed without a list() call.
        $this->refreshAllUnfinished();

        $cutoff = Clock::get()->now()->modify('-'.$this->config->retentionSeconds.' seconds')->format('c');

        // Find stale rows via SQL predicate (finished_at <= cutoff)
        $staleRows = $this->store->fetchStale($cutoff);

        $count = 0;
        foreach ($staleRows as $row) {
            $id = $row['id'] ?? 0;
            $logPath = \is_string($row['log_path'] ?? null) ? $row['log_path'] : '';
            $statusPath = \is_string($row['status_path'] ?? null) ? $row['status_path'] : '';

            // Delete log and status files
            $this->lifecycle->deleteRecordFiles($logPath, $statusPath);

            // Delete DB record
            if ($this->store->deleteById((int) $id)) {
                ++$count;
            }
        }

        // Also clean up orphaned .pid files (from crashed processes)
        $activePids = $this->store->fetchAllUnfinishedPids();
        $activePidSet = [];
        foreach ($activePids as $activePid) {
            if (\is_int($activePid) || \is_string($activePid)) {
                $activePidSet[(string) $activePid] = true;
            }
        }
        $this->lifecycle->cleanupOrphanedPidFiles($activePidSet);

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
        $this->store->ensureTable();

        $running = $this->store->fetchAllUnfinished($sessionId);

        $count = 0;
        foreach ($running as $row) {
            $pidVal = $row['pid'] ?? 0;
            $pid = is_numeric($pidVal) ? (int) $pidVal : 0;
            try {
                $this->stop($pid);
                ++$count;
            } catch (\RuntimeException $e) {
                // Process may have exited between fetch and stop; log and continue
                $this->logger->warning('background_process.shutdown_cleanup_error', [
                    'component' => 'tool.background_process',
                    'event_type' => 'background_process.shutdown_cleanup_error',
                    'process_pid' => $pid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    // ─── Private helpers ─────────────────────────────────────────────

    /**
     * Convert a raw DB row into a BackgroundProcessRecord with
     * filesystem-refreshed status.
     *
     * Injects the computed 'status' key before delegating to the store's
     * normalizeRow() for DTO conversion.
     *
     * @param array<string, mixed> $row
     */
    private function normalizeRow(array $row): BackgroundProcessRecord
    {
        $this->enrichWithStatus($row);

        // Inject computed status — not a real DB column, added for DTO hydration
        return $this->store->normalizeRow($row);
    }

    /**
     * Enrich a single row's status from the filesystem, updating the DB
     * if the process has completed since the last check.
     *
     * @param array<string, mixed> $row Row data, passed by reference and mutated in place
     */
    private function enrichWithStatus(array &$row): void
    {
        // Already finished
        if (null !== ($row['finished_at'] ?? null)) {
            $stopped = (bool) ($row['stopped_by_user'] ?? false);
            $row['status'] = $stopped ? 'stopped' : 'finished';

            return;
        }

        $pid = is_numeric($row['pid'] ?? 0) ? (int) $row['pid'] : 0;
        $statusPath = $row['status_path'] ?? null;
        $id = is_numeric($row['id'] ?? 0) ? (int) $row['id'] : 0;

        // Check status file first (process might have finished normally)
        $exitCode = $this->lifecycle->readStatusFile($statusPath);
        if (null !== $exitCode) {
            $now = Clock::get()->now()->format('c');
            $row['finished_at'] = $now;
            $row['exit_code'] = $exitCode;

            $this->store->markFinished($id, $exitCode, $now);
            $row['status'] = 0 === $exitCode ? 'finished' : 'finished (exit code '.$exitCode.')';

            return;
        }

        // Check /proc/<pid> for liveness
        if ($this->lifecycle->isAlive($pid)) {
            $row['status'] = 'running';

            return;
        }

        // Process is gone but no status file written (crash / SIGKILL / unclean exit)
        $now = Clock::get()->now()->format('c');
        $row['finished_at'] = $now;
        $row['exit_code'] = null;

        $this->logger->info('background_process.finished_unclean', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.finished_unclean',
            'process_pid' => $pid,
        ]);

        $this->store->markFinished($id, null, $now);
        $row['status'] = 'finished (unclean)';
    }

    /**
     * Refresh a single record by ID, checking filesystem state and
     * updating the DB row if the process has finished.
     */
    private function refreshSingleRecord(int $id): void
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->store->fetchById($id);

        if (null === $row) {
            return;
        }

        $this->enrichWithStatus($row);
    }

    /**
     * Refresh all unfinished records so finished_at is populated
     * for processes that completed without a list() call.
     */
    private function refreshAllUnfinished(): void
    {
        $rows = $this->store->fetchAllUnfinished();

        foreach ($rows as $row) {
            $this->enrichWithStatus($row);
        }
    }
}
