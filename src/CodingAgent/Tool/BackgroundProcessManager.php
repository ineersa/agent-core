<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tool\BackgroundProcess\LogTailResult;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StartResult;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StopResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Durable, ORM-backed manager for background processes.
 *
 * This is a facade that delegates database operations to ProcessStore
 * and OS/filesystem operations to ProcessLifecycle.
 *
 * Process lifecycle:
 *  1. start() persists a BackgroundProcess entity and launches the
 *     command in a new session/process group via setsid. A shell
 *     wrapper backgrounds the command as a distinct child process,
 *     redirects stdout/stderr to a log file, and records exit status
 *     to a status file on completion. The wrapper traps SIGTERM and
 *     forwards it to the child so TERM reaches the actual workload.
 *  2. The process runs independently — the PHP tool worker exits while
 *     the child continues in its own session.
 *  3. On subsequent list() calls, resolveEntityStatus() checks
 *     liveness via /proc/<pid> on Linux or the status file.
 *  4. stop() sends SIGTERM to the process group (negative PGID), waits
 *     the configured grace period, sends SIGKILL if still alive.
 *  5. cleanupStale() removes DB records and log files older than
 *     retention once the process has finished.
 *
 * Session ownership: every process stores an optional session_id.
 * Methods that accept ?string $sessionId scope operations to that
 * session when provided; null means unscoped/admin (show all,
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
 * In test environment (config/services_test.yaml), the shutdown handler
 * is intentionally omitted. IsolatedKernelTestCase removes the SQLite
 * database directory in tearDown() before PHP process shutdown, so a
 * registered shutdown function that queries Doctrine would crash with
 * "unable to open database file".
 *
 * Crash resilience: SIGKILL, OOM, or segfault bypass the shutdown
 * function, so background processes survive unexpected controller
 * death. The user can inspect logs on the next session resume.
 *
 * setsid is required. If setsid is unavailable, start() fails with a
 * clear exception rather than falling back to unsafe single-PID mode
 * that cannot reliably propagate signals to child workloads.
 *
 * Schema is managed by Doctrine migrations — no runtime DDL.
 * Migrations run once at AgentCommand startup before any manager call.
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
     * file is not written; resolveEntityStatus() marks that scenario as
     * "finished (unclean)".
     *
     * setsid is required.  If setsid is not available or fails, this
     * method throws rather than falling back to single-PID mode that
     * cannot safely propagate TERM to child workloads.
     *
     * @param string      $command   shell command to run in background.
     *                               Must already be escaped if it contains
     *                               user-controlled tokens.
     * @param string|null $sessionId optional session/run identifier for
     *                               ownership scoping. When provided, the
     *                               process is bound to this session.
     *                               Pass null for unscoped operation.
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
        // Ensure storage directory exists
        $bgDir = $this->lifecycle->ensureStorageDir();

        // Generate unique file prefix and file paths
        $filePrefix = bin2hex(random_bytes(8));
        $pidFile = $bgDir.'/'.$filePrefix.'.pid';
        $statusFile = $bgDir.'/'.$filePrefix.'.status';
        $logFile = $bgDir.'/'.$filePrefix.'.log';

        $now = Clock::get()->now();

        // Launch process in new session group
        $launchResult = $this->lifecycle->launchProcess($command, $pidFile, $logFile, $statusFile);
        $pid = $launchResult['pid'];
        $pgid = $launchResult['pgid'];

        // Persist entity with auto-increment DB id
        $dbId = $this->store->insertRecord([
            'pid' => $pid,
            'pgid' => $pgid,
            'session_id' => $sessionId ?? '',
            'command' => $command,
            'log_path' => $logFile,
            'status_path' => $statusFile,
            'started_at' => $now,
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
            status: BackgroundProcessStatusEnum::Running->value,
        );
    }

    /**
     * List all tracked background processes with refreshed status.
     *
     * Resolves status from filesystem state (/proc/<pid> or status file)
     * and persists any changes on the entity before returning.  This is
     * the canonical way to get current process state — entities returned
     * by fetchAll() may have stale in-memory status.
     *
     * @param string|null $sessionId optional session filter. When
     *                               provided, only processes for that
     *                               session are returned. Pass null for
     *                               all processes.
     *
     * @return list<BackgroundProcess>
     */
    public function list(?string $sessionId = null): array
    {
        $entities = $this->store->fetchAll($sessionId);

        foreach ($entities as $entity) {
            $this->resolveEntityStatus($entity);
        }

        $this->store->flush();

        return $entities;
    }

    /**
     * Return the tail of a background process log file.
     *
     * @throws \RuntimeException when process not found, session mismatch, or log unreadable
     */
    public function readLogTail(int $pid, ?int $maxChars = null, ?string $sessionId = null): LogTailResult
    {
        $maxChars ??= $this->config->logTailChars;

        $entity = $this->store->fetchByPid($pid);

        if (null === $entity) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d.', $pid));
        }

        if (null !== $sessionId && $entity->sessionId !== $sessionId) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d for this session.', $pid));
        }

        return $this->lifecycle->readLogTail($entity->logPath, $pid, $maxChars);
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
     * @param string|null $sessionId optional session ownership check.
     *                               When provided, only a process belonging
     *                               to this session will be stopped.
     *
     * @throws \RuntimeException when process not found, session mismatch,
     *                           or PID is invalid
     */
    public function stop(int $pid, ?string $sessionId = null): StopResult
    {
        // Defence-in-depth: reject non-positive PIDs that could cause
        // kill(0) or kill(-negative) to broadcast signals to the caller.
        if ($pid <= 0) {
            throw new \RuntimeException(\sprintf('Invalid PID %d for stop.', $pid));
        }

        $entity = $this->store->fetchByPid($pid);

        if (null === $entity) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d.', $pid));
        }

        if (null !== $sessionId && $entity->sessionId !== $sessionId) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d for this session.', $pid));
        }

        // Refresh status before acting — the process may have finished
        // since the last list() call (status file written, /proc gone).
        $this->refreshEntity($entity);

        // Re-fetch after refresh (entity may have been marked finished)
        $entity = $this->store->fetchByPid($pid);
        if (null === $entity) {
            throw new \RuntimeException(\sprintf('Background process with PID %d disappeared during refresh.', $pid));
        }

        if (null !== $entity->finishedAt) {
            return new StopResult(
                pid: $pid,
                pgid: $entity->pgid,
                stoppedByUser: false,
                alreadyFinished: true,
                signalSent: 'none',
            );
        }

        $pgid = $entity->pgid;
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

        $now = Clock::get()->now();
        $entity->markStopped($now);

        // Write -1 to the status file only when the wrapper did not
        // already write a real exit code (KILL path). When TERM succeeds
        // the wrapper's trap handler writes the child's real exit code
        // before exiting; overwriting would corrupt forensic evidence.
        $statusPath = $entity->statusPath;
        if ('' !== $statusPath) {
            $this->lifecycle->writeStopMarker($statusPath);
        }

        $this->store->flush();

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
     * First refreshes all unfinished records so finished_at is populated
     * for processes that completed without a list() call, then queries
     * for stale entities.
     *
     * @return int Number of cleaned records
     */
    public function cleanupStale(): int
    {
        // Refresh all unfinished so finished_at is populated
        $this->refreshAllUnfinished();

        $cutoff = Clock::get()->now()->modify('-'.$this->config->retentionSeconds.' seconds');

        $staleEntities = $this->store->fetchStale($cutoff);

        $count = 0;
        foreach ($staleEntities as $entity) {
            $logPath = $entity->logPath;
            $statusPath = $entity->statusPath;

            // Delete log and status files
            $this->lifecycle->deleteRecordFiles($logPath, $statusPath);

            // Delete DB record
            if ($this->store->deleteById($entity->id)) {
                ++$count;
            }
        }

        // Also clean up orphaned .pid files (from crashed processes)
        $activePids = $this->store->fetchAllUnfinishedPids();
        $activePidSet = [];
        foreach ($activePids as $activePid) {
            $activePidSet[$activePid] = true;
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
     * @param string|null $sessionId optional session filter. When provided,
     *                               only processes for that session are
     *                               stopped. Pass null to stop all running
     *                               processes (default, unscoped).
     *
     * @return int Number of processes terminated
     */
    public function shutdownCleanup(?string $sessionId = null): int
    {
        try {
            $entities = $this->store->fetchAllUnfinished($sessionId);
        } catch (TableNotFoundException) {
            // Database tables have not been created yet (e.g. during PHAR boot
            // before migrations run, or for pure CLI commands like list/about
            // that do not go through AgentCommand). No background processes can
            // be running in this case.
            $this->logger->debug('background_process.shutdown_no_table', [
                'component' => 'background_process_manager',
                'event_type' => 'shutdown_cleanup_table_not_found',
                'session_id' => $sessionId,
            ]);

            return 0;
        }

        $count = 0;
        foreach ($entities as $entity) {
            $pid = $entity->pid;
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
     * Resolve the runtime status of a background process entity.
     *
     * Check order:
     *  1. DB finished_at — already resolved, trust the persisted status.
     *  2. Status file   — process finished normally, wrapper wrote exit code.
     *  3. /proc/<pid>   — still alive, mark as Running.
     *  4. None of above — process is gone without a status file
     *     (crash / SIGKILL / unclean exit). Mark as FinishedUnclean.
     *
     * Mutates the entity in place; caller is responsible for flush.
     */
    private function resolveEntityStatus(BackgroundProcess $entity): BackgroundProcessStatusEnum
    {
        // Already resolved in DB
        if (null !== $entity->finishedAt) {
            return $entity->status;
        }

        $pid = $entity->pid;

        // Check status file first (process may have finished normally)
        $exitCode = $this->lifecycle->readStatusFile($entity->statusPath);
        if (null !== $exitCode) {
            $now = Clock::get()->now();
            $entity->finish($exitCode, $now);

            return BackgroundProcessStatusEnum::Finished;
        }

        // Check /proc/<pid> for liveness
        if ($this->lifecycle->isAlive($pid)) {
            $entity->status = BackgroundProcessStatusEnum::Running;

            return BackgroundProcessStatusEnum::Running;
        }

        // Process is gone but no status file written (crash / SIGKILL / unclean exit)
        $now = Clock::get()->now();
        $entity->markFinishedUnclean($now);

        $this->logger->info('background_process.finished_unclean', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.finished_unclean',
            'process_pid' => $pid,
        ]);

        return BackgroundProcessStatusEnum::FinishedUnclean;
    }

    /**
     * Refresh a single entity's status from filesystem state.
     *
     * Resolves and persists the current status; used before stop()
     * to avoid acting on a process that already finished since last
     * list() call.
     */
    private function refreshEntity(BackgroundProcess $entity): void
    {
        $this->resolveEntityStatus($entity);
        $this->store->flush();
    }

    /**
     * Refresh all unfinished entities so finished_at is populated
     * for processes that completed without a list() call.
     */
    private function refreshAllUnfinished(): void
    {
        $entities = $this->store->fetchAllUnfinished();

        foreach ($entities as $entity) {
            $this->resolveEntityStatus($entity);
        }

        $this->store->flush();
    }
}
