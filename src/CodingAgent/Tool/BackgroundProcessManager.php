<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\CodingAgent\Application\BackgroundProcess\BashProcessActionEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessSnapshotDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RequestBashBackgroundDTO;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\BashProcessRecordDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\ForegroundBashProcessDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\NativeBashLaunchDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\NativeBashObservationDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\LogTailResult;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StartResult;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StopResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Finder\Finder;

use function Amp\delay;

/**
 * Native process supervision with owner-backed durable metadata.
 *
 * This is a facade that delegates database operations to ProcessStore
 * and OS/filesystem operations to ProcessLifecycle.
 *
 * Process lifecycle:
 *  1. start() launches the command in a new session/process group via setsid.
 *     Bash persists actual launch facts after native startup. A shell
 *     wrapper backgrounds the command as a distinct child process,
 *     redirects stdout/stderr to a log file, and records exit status
 *     to a status file on completion. The wrapper traps SIGTERM and
 *     forwards it to the child so TERM reaches the actual workload.
 *  2. The process runs independently — the PHP tool worker exits while
 *     the child continues in its own session.
 *  3. Subsequent accepted-background reads ask the metadata owner to observe
 *     liveness via /proc/<pid> on Linux or the status file.
 *  4. stop() sends SIGTERM to the process group (negative PGID), polls
 *     until exit or the configured grace deadline, then SIGKILL if still alive.
 *
 * Session ownership: user-facing list(), readLogTail(), and
 * stop() operations require an owning session and expose only rows explicitly
 * accepted as background work. Instance shutdown stops only native launches
 * still held by that instance; accepted launches have transferred out.
 *
 * Shutdown handling:
 * Call registerShutdownHandler() from production wiring (services.yaml)
 * to register a PHP shutdown function that calls shutdownCleanup() with
 * no session argument. That path reaps only unaccepted local native launches,
 * not accepted jobs or every unfinished row in the shared database.
 * This covers graceful exit (Ctrl+C, quit command,
 * normal script end) and fatal errors.
 *
 * In test environment (config/services_test.yaml), the shutdown handler
 * is intentionally omitted. IsolatedKernelTestCase removes the SQLite
 * database directory in tearDown() before PHP process shutdown, so a
 * registered shutdown function that queries Doctrine would crash with
 * "unable to open database file".
 *
 * Crash resilience: SIGKILL, OOM, or segfault bypass the shutdown
 * function, so controller startup removes accepted process state left by
 * the prior controller after it regains exclusive session ownership.
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

    /** @var array<string, NativeBashLaunchDTO> statusPath => native launch */
    private array $nativeLaunches = [];

    /** @var array<int, int> Immutable record ID to monotonic TERM grace deadline. */
    private array $timeoutGraceDeadlines = [];

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
     * Register a PHP shutdown function that terminates background
     * processes this PHP instance started when the process exits.
     *
     * On graceful shutdown (exit, Ctrl+C, fatal error), this calls
     * shutdownCleanup() with no session id, which TERM→grace→KILLs only
     * owned records from start() on this manager instance — never foreign
     * unfinished rows from the shared background-process database.
     * On hard crash (SIGKILL, OOM, segfault), the shutdown function
     * does not fire — BG processes survive for inspection or resume.
     *
     * Safe to call multiple times — idempotent.
     *
     * Tool workers also construct this manager. Accepted jobs release their
     * local handle and belong to the session, not the retiring worker.
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
     * Launch and supervise a native process locally.
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
     * file is not written; the owner observation marks that scenario as
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
    public function start(
        string $command,
        ?string $sessionId = null,
        ?string $toolCallId = null,
        ?int $deadlineNanoseconds = null,
        bool $backgroundRequested = false,
        ?string $invocationKey = null,
    ): StartResult {
        $runId = $sessionId ?? '';
        $scoped = '' !== $runId && null !== $toolCallId && '' !== $toolCallId;
        $prefix = $scoped
            ? hash('sha256', $runId).'-'.hash('sha256', $invocationKey ?? $toolCallId)
            : bin2hex(random_bytes(16));
        $directory = $this->lifecycle->ensureStorageDir();
        $pidFile = $directory.'/'.$prefix.'.pid';
        $statusFile = $directory.'/'.$prefix.'.status';
        $logFile = $directory.'/'.$prefix.'.log';

        return $this->lifecycle->withSidecarLock($statusFile, function () use ($command, $runId, $toolCallId, $deadlineNanoseconds, $backgroundRequested, $scoped, $pidFile, $statusFile, $logFile): StartResult {
            if (!$this->lifecycle->ownsSidecarPaths($logFile, $statusFile)) {
                throw new \RuntimeException('Bash native paths are not owned sidecars.');
            }
            $id = $scoped ? $this->store->recordIdForStatusPath($statusFile, $runId) : null;
            $record = null === $id ? null : $this->store->snapshot($id);
            if (null !== $record && ($record->command !== $command || $record->toolCallId !== $toolCallId || $record->logPath !== $logFile)) {
                throw new \RuntimeException('Bash intent conflicts with its existing native execution.');
            }
            $evidence = null !== $record || file_exists($pidFile) || file_exists($statusFile) || file_exists($logFile);
            $startedAt = $record?->startedAt;
            if ($evidence) {
                $pid = $this->lifecycle->readPidFile($statusFile);
                if (null === $pid || (null !== $record?->pid && $record->pid !== $pid)) {
                    throw new \RuntimeException('Bash execution has incomplete native identity; workload outcome is unknown.');
                }
                $pgid = $record?->pgid;
                if (null === $this->lifecycle->readStatusFile($statusFile) && $this->lifecycle->isAlive($pid)) {
                    $pgid = $this->lifecycle->ownedPgid($pid, $statusFile);
                    if (null === $pgid || (null !== $record?->pgid && $record->pgid !== $pgid)) {
                        throw new \RuntimeException('Bash execution has no recoverable owned process group; workload outcome is unknown.');
                    }
                }
            } else {
                $launched = $this->lifecycle->launchProcess($command, $pidFile, $logFile, $statusFile);
                $pid = $launched['pid'];
                $pgid = $launched['pgid'];
            }
            if (null === $startedAt) {
                clearstatcache(true, $pidFile);
                $timestamp = filemtime($pidFile);
                if (false === $timestamp) {
                    throw new \RuntimeException('Bash execution has no recoverable native start time.');
                }
                $startedAt = new \DateTimeImmutable('@'.$timestamp);
            }
            $deadline = null !== $record ? $record->deadlineNanoseconds : $deadlineNanoseconds;
            if ($evidence && null === $record && null !== $deadlineNanoseconds) {
                // Preserve the caller's budget when recovering a launch whose
                // post-launch SQL insert was interrupted. The PID file dates
                // the original launch; do not restart its timeout on replay.
                $elapsed = max(0.0, (float) Clock::get()->now()->format('U.u') - (float) $startedAt->format('U.u'));
                $deadline = $deadlineNanoseconds - (int) ($elapsed * 1_000_000_000);
            }
            if ($scoped && null === $id) {
                $id = $this->store->insertRecord([
                    'session_id' => $runId, 'tool_call_id' => $toolCallId, 'command' => $command,
                    'pid' => $pid, 'pgid' => $pgid, 'log_path' => $logFile, 'status_path' => $statusFile,
                    'started_at' => $startedAt, 'deadline_nanoseconds' => $deadline,
                    'background_requested' => $backgroundRequested,
                ]);
            }
            $native = new NativeBashLaunchDTO(
                pid: $pid, pgid: $pgid, sessionId: $runId, command: $command, toolCallId: $toolCallId,
                deadlineNanoseconds: $deadline,
                backgroundRequested: $record->backgroundRequested ?? $backgroundRequested,
                logPath: $logFile, statusPath: $statusFile, startedAt: $startedAt,
            );
            $accepted = null !== $record?->backgroundedAt;
            if (!$accepted) {
                $this->nativeLaunches[$statusFile] = $native;
                $this->observeNative($native);
            }

            return new StartResult($pid, $logFile, $statusFile, $native->deadlineNanoseconds, $accepted);
        });
    }

    /**
     * @return list<BashProcessRecordDTO>
     */
    public function list(string $sessionId): array
    {
        $records = [];
        foreach ($this->store->backgroundSnapshots($sessionId) as $candidate) {
            $record = $this->findByRecordId($candidate->id, $sessionId);
            if (null !== $record) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /** Scalar COUNT distinguishes a missing row from a filtered lookup. */
    public function existsByRecordId(int $id): bool
    {
        return $this->store->existsByRecordId($id);
    }

    /**
     * Public PID lookup selects the newest retained row before filtering
     * ownership. Null covers both absence and a different session. Immutable record
     * IDs, not reusable PIDs, address foreground supervision.
     */
    public function find(int $pid, ?string $sessionId = null): ?BashProcessRecordDTO
    {
        $record = $this->store->latestSnapshot($pid);

        return null === $record ? null : $this->findByRecordId($record->id, $sessionId);
    }

    public function findByRecordId(int $id, ?string $sessionId = null): ?BashProcessRecordDTO
    {
        $record = $this->store->snapshot($id);
        if (null === $record || (null !== $sessionId && $record->sessionId !== $sessionId)) {
            return null;
        }
        if (null === $record->finishedAt) {
            $this->observeNativeAndPublish($record);
            $record = $this->store->snapshot($id) ?? $record;
        }

        return $record;
    }

    public function findByStatusPath(string $statusPath, ?string $sessionId = null): ?BashProcessRecordDTO
    {
        // Durable lookup only. Local supervisors use observeNativeByStatusPath().
        $resolvedSession = $sessionId ?? ($this->nativeLaunches[$statusPath]->sessionId ?? null);
        if (null === $resolvedSession) {
            return null;
        }
        $id = $this->store->recordIdForStatusPath($statusPath, $resolvedSession);
        if (null === $id) {
            return null;
        }

        return $this->findByRecordId($id, $resolvedSession);
    }

    /**
     * Observe the exact native launch identified by statusPath.
     *
     * Returns local supervision facts only. Never fabricates a durable row id.
     */
    public function observeNativeByStatusPath(string $statusPath, ?string $sessionId = null): ?NativeBashObservationDTO
    {
        $native = $this->nativeLaunches[$statusPath] ?? null;
        if (null === $native && null !== $sessionId) {
            $record = $this->findByStatusPath($statusPath, $sessionId);
            if (null !== $record?->pid && null !== $record->startedAt) {
                $native = new NativeBashLaunchDTO($record->pid, $record->pgid, $record->sessionId, $record->command, $record->toolCallId, $record->deadlineNanoseconds, $record->backgroundRequested, $record->logPath, $record->statusPath, $record->startedAt);
            }
        }
        if (null === $native) {
            return null;
        }
        if (null !== $sessionId && $native->sessionId !== $sessionId) {
            return null;
        }

        return $this->observeNative($native);
    }

    public function readLogTail(string $sessionId, int $pid, ?int $maxChars = null): LogTailResult
    {
        $id = $this->store->acceptedRecordId($sessionId, $pid);
        if (null === $id) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d for this session.', $pid));
        }

        return $this->readLogTailForRecord($id, $maxChars, $sessionId);
    }

    public function readLogTailForRecord(int $recordId, ?int $maxChars = null, ?string $sessionId = null): LogTailResult
    {
        $record = $this->findByRecordId($recordId, $sessionId);
        if (null === $record) {
            throw new \RuntimeException(\sprintf('No background process found with record ID %d for this session.', $recordId));
        }

        return $this->lifecycle->readLogTail($record->logPath, $maxChars ?? $this->config->logTailChars, null !== $record->finishedAt);
    }

    public function isAcceptedRecord(int $recordId, string $runId): bool
    {
        return $this->store->isAcceptedRecord($recordId, $runId);
    }

    /**
     * Stop a background process: TERM → grace → KILL.
     *
     * Targets the entire process group via negative PGID
     * (kill -TERM -<PGID>) only after validating the live wrapper and owned group.
     * Unverifiable native ownership refuses signalling; there is no PID fallback.
     *
     * @throws \RuntimeException when process not found or PID is invalid
     */
    public function stop(int $pid, string $sessionId): StopResult
    {
        // Defence-in-depth: reject non-positive PIDs that could cause
        // kill(0) or kill(-negative) to broadcast signals to the caller.
        if ($pid <= 0) {
            throw new \RuntimeException(\sprintf('Invalid PID %d for stop.', $pid));
        }

        $recordId = $this->store->acceptedRecordId($sessionId, $pid);

        if (null === $recordId) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d for this session.', $pid));
        }

        return $this->stopProcessRecord($recordId, $sessionId);
    }

    /**
     * Request detachment of one exact foreground Bash invocation.
     *
     * This does not make a process notification-eligible. Its foreground worker
     * checks completion first and accepts the request only when returning the
     * original tool result as an acknowledgment. A finish/request race therefore
     * cannot produce both a foreground result and a completion notification.
     */
    public function requestBackground(string $runId, int $recordId, string $toolCallId): bool
    {
        if ('' === $runId || '' === $toolCallId) {
            return false;
        }

        $request = new RequestBashBackgroundDTO($runId, $recordId, $toolCallId);

        return $this->store->requestBackground($request, $this->lifecycle);
    }

    /**
     * Read-only selection snapshot. Native supervision and deadline enforcement
     * remain with the execution owner/poller, not TUI candidate discovery.
     *
     * @return list<ForegroundBashProcessDTO>
     */
    public function foregroundTools(string $runId): array
    {
        if ('' === $runId) {
            return [];
        }

        $candidates = [];
        foreach ($this->store->fetchForegroundTools($runId) as $process) {
            // Candidate discovery reads actual launch metadata without enforcing deadlines.
            $pid = $process->pid ?? $this->lifecycle->readPidFile($this->store->snapshot($process->id)?->statusPath);
            if (null !== $pid) {
                $candidates[] = new ForegroundBashProcessDTO($process->id, $pid, $process->toolCallId);
            }
        }

        return $candidates;
    }

    /**
     * Resolve a still-running background acceptance candidate.
     *
     * Callers must perform the freshest cancellation check after this returns
     * and before {@see acceptResolvedBackground()}, with no yield between them.
     *
     * @return array{kind: 'native', statusPath: string, pid: int, sessionId: string}|array{kind: 'record', statusPath: string, recordId: int, pid: ?int, sessionId: string}|null
     */
    public function resolveBackgroundAcceptance(string $statusPath, ?string $sessionId = null): ?array
    {
        $native = $this->nativeLaunches[$statusPath] ?? null;
        if (null !== $native) {
            $observed = $this->observeNative($native);
            if (null !== $sessionId && $observed->sessionId !== $sessionId) {
                throw new \RuntimeException('Bash background acceptance record/session mismatch.');
            }
            if (null !== $observed->finishedAt) {
                return null;
            }

            return [
                'kind' => 'native',
                'statusPath' => $statusPath,
                'pid' => $observed->pid,
                'sessionId' => $sessionId ?? $observed->sessionId,
            ];
        }

        $record = $this->findByStatusPath($statusPath, $sessionId);
        if (null === $record || (null !== $sessionId && $record->sessionId !== $sessionId)) {
            throw new \RuntimeException('Bash background acceptance record/session mismatch.');
        }
        if (null !== $record->finishedAt) {
            return null;
        }

        return [
            'kind' => 'record',
            'statusPath' => $statusPath,
            'recordId' => $record->id,
            'pid' => $record->pid,
            'sessionId' => $sessionId ?? $record->sessionId,
        ];
    }

    /**
     * Commit local acceptance for a candidate resolved by {@see resolveBackgroundAcceptance()}.
     *
     * @param array{kind: 'native', statusPath: string, pid: int, sessionId: string}|array{kind: 'record', statusPath: string, recordId: int, pid: ?int, sessionId: string} $candidate
     */
    public function acceptResolvedBackground(array $candidate): bool
    {
        $statusPath = $candidate['statusPath'];
        if ('native' === $candidate['kind']) {
            $native = $this->nativeLaunches[$statusPath] ?? null;
            if (null === $native || $native->pid !== $candidate['pid'] || $native->sessionId !== $candidate['sessionId']) {
                throw new \RuntimeException('Bash background acceptance native/session mismatch.');
            }
            $id = $this->store->recordIdForStatusPath($statusPath, $candidate['sessionId']);
            if (null === $id || !$this->store->acceptLocal($id, $candidate['sessionId'])) {
                throw new \RuntimeException('Background acceptance requires the launched process record.');
            }
            $this->logger->info('background_process.marked_backgrounded', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.marked_backgrounded',
                'process_pid' => $candidate['pid'],
                'status_path' => $statusPath,
                'process_session_id' => $candidate['sessionId'],
                'run_id' => $candidate['sessionId'],
                'session_id' => $candidate['sessionId'],
            ]);
            $this->releaseNativeLaunch($statusPath);

            return true;
        }

        if ($candidate['recordId'] > 0) {
            if (!$this->store->acceptLocal($candidate['recordId'], $candidate['sessionId'])) {
                throw new \RuntimeException('Bash background acceptance record/session mismatch.');
            }
        }
        $this->logger->info('background_process.marked_backgrounded', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.marked_backgrounded',
            'process_pid' => $candidate['pid'],
            'status_path' => $statusPath,
            'record_id' => $candidate['recordId'],
            'process_session_id' => $candidate['sessionId'],
            'run_id' => $candidate['sessionId'],
            'session_id' => $candidate['sessionId'],
        ]);
        $this->releaseNativeLaunch($statusPath);

        return true;
    }

    /**
     * Stop an exact local native launch or an accepted background record.
     *
     * Same TERM → grace → KILL lifecycle as stop(), without creating metadata
     * for a foreground invocation.
     * Nonblocking callers leave grace and escalation to the owning controller.
     *
     * @throws \RuntimeException when record not found, session mismatch, or invalid state
     */
    public function stopByStatusPath(string $statusPath, ?string $sessionId = null, bool $waitForExit = true): StopResult
    {
        $native = $this->nativeLaunches[$statusPath] ?? null;
        if (null !== $native) {
            if (null !== $sessionId && $native->sessionId !== $sessionId) {
                throw new \RuntimeException(\sprintf('No background process found with status path %s for this session.', $statusPath));
            }

            return $this->lifecycle->withSidecarLock($statusPath, function () use ($native, $statusPath, $waitForExit): StopResult {
                $result = $this->lifecycle->stopProcess($native->pid, $native->pgid, $statusPath, $waitForExit);
                $this->observeNative($native);

                return $result;
            });
        }
        $record = $this->findByStatusPath($statusPath, $sessionId);
        if (null === $record || $record->id <= 0) {
            throw new \RuntimeException(\sprintf('No background process found with status path %s for this session.', $statusPath));
        }

        return $this->stopByRecordId($record->id, $sessionId, $waitForExit);
    }

    /**
     * Read a log by the exact native paths retained from start().
     *
     * Used after local supervision releases the in-memory launch map.
     */
    public function readLogTailForNativePaths(string $logPath, string $statusPath, ?int $maxChars = null): LogTailResult
    {
        $finished = null !== $this->lifecycle->readStatusFile($statusPath);

        return $this->lifecycle->readLogTail($logPath, $maxChars ?? $this->config->logTailChars, $finished);
    }

    /**
     * Release the local native launch after the caller finished consuming output.
     *
     * Unaccepted launches delete their exact .pid/.status/.log set.
     * Accepted detached launches already released via {@see acceptResolvedBackground()}.
     */
    public function releaseNativeInvocation(string $statusPath): void
    {
        $native = $this->nativeLaunches[$statusPath] ?? null;
        if (null === $native) {
            return;
        }

        // Exceptional paths may still own a running unaccepted launch. Stop it
        // with existing native supervision before dropping the handle. Accepted
        // detach already released, so this path never signals those jobs.
        $exitCode = $this->lifecycle->readStatusFile($statusPath);
        if (null === $exitCode && $this->lifecycle->isAlive($native->pid)) {
            $this->stopByStatusPath($statusPath, '' !== $native->sessionId ? $native->sessionId : null);
            $native = $this->nativeLaunches[$statusPath] ?? null;
            if (null === $native) {
                return;
            }
        }

        $logPath = $native->logPath;
        $sessionId = $native->sessionId;
        $pid = $native->pid;
        $this->releaseNativeLaunch($statusPath);
        if ('' !== $sessionId && null !== $native->toolCallId) {
            return;
        }
        $deleted = $this->lifecycle->withSidecarLock($statusPath, function () use ($logPath, $statusPath): bool {
            return $this->lifecycle->deleteLockedRecordSidecars($logPath, $statusPath);
        });
        if (!$deleted) {
            $this->logger->error('background_process.native_sidecar_cleanup_failed', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.native_sidecar_cleanup_failed',
                'status_path' => $statusPath,
                'log_path' => $logPath,
                'process_pid' => $pid,
                'process_session_id' => $sessionId,
                'run_id' => $sessionId,
                'session_id' => $sessionId,
            ]);
        }
    }

    public function stopByRecordId(int $recordId, ?string $sessionId = null, bool $waitForExit = true): StopResult
    {
        if ($recordId <= 0) {
            throw new \RuntimeException(\sprintf('Invalid record ID %d for stop.', $recordId));
        }

        $runId = $this->store->sessionForRecord($recordId);
        if (null === $runId || (null !== $sessionId && $runId !== $sessionId)) {
            throw new \RuntimeException(\sprintf('No background process found with record ID %d for this session.', $recordId));
        }

        return $this->stopProcessRecord($recordId, $runId, $waitForExit);
    }

    /**
     * Terminate background processes started by this manager instance.
     *
     * This PHP-shutdown fallback stops only unaccepted local foreground
     * launches still tracked in nativeLaunches. Accepted background jobs belong
     * to the session owner; the controller lifecycle listener owns session-wide
     * cleanup.
     *
     * @return int Number of processes terminated
     */
    public function shutdownCleanup(): int
    {
        return $this->reapUnacceptedNativeLaunches();
    }

    /** Stop unreported wrappers after launchers stop; only deletion erases replay evidence. */
    public function cleanupSessionSidecars(string $runId, bool $permanentDeletion = false): void
    {
        $directory = $this->lifecycle->ensureStorageDir();
        $prefix = hash('sha256', $runId).'-';
        foreach ((new Finder())->files()->in($directory)->depth('== 0')->name('/^'.preg_quote($prefix, '/').'[a-f0-9]{64}\.pid$/') as $file) {
            $statusPath = substr($file->getPathname(), 0, -4).'.status';
            $logPath = substr($file->getPathname(), 0, -4).'.log';
            $this->lifecycle->withSidecarLock($statusPath, function () use ($runId, $statusPath, $logPath, $permanentDeletion): void {
                if (null !== $this->store->recordIdForStatusPath($statusPath, $runId)) {
                    return; // A failed row cleanup must retain its native evidence.
                }
                if (!$this->lifecycle->ownsSidecarPaths($logPath, $statusPath)) {
                    throw new \RuntimeException('Session native cleanup encountered unsafe sidecars.');
                }
                $pid = $this->lifecycle->readPidFile($statusPath);
                if (null === $pid) {
                    throw new \RuntimeException('Session native cleanup cannot establish wrapper ownership.');
                }
                $this->lifecycle->stopProcess($pid, $this->lifecycle->ownedPgid($pid, $statusPath), $statusPath);
                if ($permanentDeletion && !$this->lifecycle->deleteLockedRecordSidecars($logPath, $statusPath)) {
                    throw new \RuntimeException('Session native sidecar cleanup failed.');
                }
            });
        }
    }

    /**
     * Permanent deletion removes finished metadata and its exact native sidecars
     * under the same lock used for launch, attachment and acceptance.
     */
    public function cleanupFinishedRecord(int $recordId, string $runId): bool
    {
        $record = $this->store->snapshot($recordId);
        if (null === $record || $record->sessionId !== $runId) {
            return false;
        }

        return $this->lifecycle->withSidecarLock($record->statusPath, function () use ($recordId, $runId): bool {
            $record = $this->store->snapshot($recordId);
            if (null === $record || $record->sessionId !== $runId || null === $record->finishedAt) {
                return false;
            }
            if (!$this->lifecycle->deleteLockedRecordSidecars($record->logPath, $record->statusPath)) {
                return false;
            }

            return null !== $this->processDecision($recordId, $runId, BashProcessActionEnum::DeleteFinished);
        });
    }

    /**
     * Native controller supervision uses scalar snapshots. ProcessLifecycle
     * validates current native evidence again before each signal.
     *
     * @param list<BashProcessRecordDTO>                            $records
     * @param \Closure(BashProcessRecordDTO, \Throwable): void|null $onError
     *
     * @return list<BashProcessRecordDTO>
     */
    public function refreshRecords(array $records, ?\Closure $onError = null): array
    {
        foreach (array_keys($this->timeoutGraceDeadlines) as $id) {
            $record = $this->store->snapshot($id);
            if (null === $record || null !== $record->finishedAt) {
                unset($this->timeoutGraceDeadlines[$id]);
            }
        }
        $refreshed = [];
        foreach ($records as $record) {
            try {
                $current = $this->processDecision($record->id, $record->sessionId, BashProcessActionEnum::Observe);
                if (null === $current || $current->finished) {
                    unset($this->timeoutGraceDeadlines[$record->id]);
                } else {
                    $snapshot = $this->store->snapshot($record->id);
                    if (null !== $snapshot && null !== $snapshot->backgroundedAt) {
                        if ($snapshot->stoppedByUser && !isset($this->timeoutGraceDeadlines[$record->id])) {
                            $this->stopProcessRecord($record->id, $record->sessionId, waitForExit: false);
                        } elseif (null !== $snapshot->deadlineNanoseconds && hrtime(true) >= $snapshot->deadlineNanoseconds) {
                            $this->superviseTimeout($snapshot);
                        }
                        if (isset($this->timeoutGraceDeadlines[$record->id]) && hrtime(true) >= $this->timeoutGraceDeadlines[$record->id]) {
                            $this->settleExpiredGrace($snapshot);
                        }
                    }
                }
                $snapshot = $this->store->snapshot($record->id);
                if (null !== $snapshot) {
                    $refreshed[] = $snapshot;
                }
            } catch (\Throwable $error) {
                if (null === $onError) {
                    throw $error;
                }
                $onError($record, $error);
            }
        }

        return $refreshed;
    }

    private function superviseTimeout(BashProcessRecordDTO $record): void
    {
        if (isset($this->timeoutGraceDeadlines[$record->id])) {
            return;
        }
        $this->lifecycle->withSidecarLock($record->statusPath, function () use ($record): void {
            $decision = $this->processDecision($record->id, $record->sessionId, BashProcessActionEnum::Timeout);
            if (null === $decision || $decision->finished || !$decision->signalRequired) {
                return;
            }
            if (null === $decision->pid) {
                return;
            }
            $this->lifecycle->sendTerm($decision->pid, $decision->pgid, $decision->statusPath);
            $this->timeoutGraceDeadlines[$record->id] = hrtime(true) + $this->config->stopGraceSeconds * 1_000_000_000;
            $this->logger->info('background_process.timed_out', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.timed_out',
                'run_id' => $record->sessionId,
                'session_id' => $record->sessionId,
                'record_id' => $record->id,
            ]);
        });
    }

    private function settleExpiredGrace(BashProcessRecordDTO $record): void
    {
        $this->lifecycle->withSidecarLock($record->statusPath, function () use ($record): void {
            $decision = $this->processDecision($record->id, $record->sessionId, BashProcessActionEnum::Observe);
            if (null !== $decision && !$decision->finished && null !== $decision->pid) {
                $this->lifecycle->sendKill($decision->pid, $decision->pgid, $decision->statusPath);
                $this->lifecycle->writeStopMarker($decision->statusPath);
                $this->processDecision($record->id, $record->sessionId, BashProcessActionEnum::SettleTimeout);
            }
            unset($this->timeoutGraceDeadlines[$record->id]);
        });
    }

    /**
     * Native stop supervision retains only immutable snapshots across yields.
     */
    private function stopProcessRecord(int $recordId, string $runId, bool $waitForExit = true): StopResult
    {
        $identity = $this->processDecision($recordId, $runId, BashProcessActionEnum::Observe);
        if (null === $identity) {
            throw new \RuntimeException('Background process is no longer available.');
        }

        // Maintenance must not delete completion evidence between the owner
        // decision and native signalling. Owner decisions do not take this lock.
        return $this->lifecycle->withSidecarLock($identity->statusPath, function () use ($recordId, $runId, $waitForExit): StopResult {
            $process = $this->processDecision($recordId, $runId, BashProcessActionEnum::Stop);
            if (null === $process) {
                throw new \RuntimeException('Background process is no longer available.');
            }
            if ($process->finished || !$process->signalRequired) {
                unset($this->timeoutGraceDeadlines[$recordId]);

                return new StopResult(pgid: $process->pgid, alreadyFinished: $process->finished, signalSent: 'none');
            }
            $pid = $process->pid;
            $pgid = $process->pgid;
            $statusPath = $process->statusPath;
            $graceSeconds = $this->config->stopGraceSeconds;
            $signalSent = 'none';
            if (null === $pid) {
                unset($this->timeoutGraceDeadlines[$recordId]);

                return new StopResult(pgid: $pgid, alreadyFinished: false, signalSent: 'none');
            }
            if (!isset($this->timeoutGraceDeadlines[$recordId])) {
                $this->lifecycle->sendTerm($pid, $pgid, $statusPath);
                $signalSent = 'term';
                $this->timeoutGraceDeadlines[$recordId] = hrtime(true) + $graceSeconds * 1_000_000_000;
            }
            if (!$waitForExit) {
                return new StopResult(pgid: $pgid, alreadyFinished: false, signalSent: $signalSent);
            }
            if ($graceSeconds > 0) {
                $deadlineNs = $this->timeoutGraceDeadlines[$recordId];
                while (hrtime(true) < $deadlineNs) {
                    if (null !== $this->lifecycle->readStatusFile($statusPath) || !$this->lifecycle->isAlive($pid)) {
                        break;
                    }
                    delay(0.05);
                }
            }
            if (null === $this->lifecycle->readStatusFile($statusPath) && $this->lifecycle->isAlive($pid)) {
                $signalSent = 'term+kill';
                $this->lifecycle->sendKill($pid, $pgid, $statusPath);
            }
            $this->logger->info('background_process.stopped', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.stopped',
                'run_id' => $runId,
                'record_id' => $recordId,
                'process_pid' => $pid,
                'process_pgid' => $pgid,
                'signal_sent' => $signalSent,
                'grace_seconds' => $graceSeconds,
            ]);
            // Preserve the wrapper's real exit code; only an absent marker gets -1.
            if ('' !== $statusPath) {
                $this->lifecycle->writeStopMarker($statusPath);
            }
            $this->processDecision($recordId, $runId, BashProcessActionEnum::SettleStop);
            unset($this->timeoutGraceDeadlines[$recordId]);

            return new StopResult(pgid: $pgid, alreadyFinished: false, signalSent: $signalSent);
        });
    }

    private function observeNative(NativeBashLaunchDTO $native): NativeBashObservationDTO
    {
        $exitCode = $this->lifecycle->readStatusFile($native->statusPath);
        $finished = null !== $exitCode || !$this->lifecycle->isAlive($native->pid);
        $stoppedByUser = false;
        $status = BackgroundProcessStatusEnum::Running;
        $finishedAt = null;
        $id = '' === $native->sessionId ? null : $this->store->recordIdForStatusPath($native->statusPath, $native->sessionId);
        $record = null === $id ? null : $this->store->snapshot($id);
        $backgroundRequested = $native->backgroundRequested || ($record->backgroundRequested ?? false);
        if ($finished) {
            if (-1 === $exitCode) {
                $status = BackgroundProcessStatusEnum::Stopped;
                $stoppedByUser = true;
            } elseif (null !== $exitCode) {
                $status = BackgroundProcessStatusEnum::Finished;
            } else {
                $status = BackgroundProcessStatusEnum::FinishedUnclean;
            }
            $finishedAt = $record->finishedAt ?? Clock::get()->now();
            $this->publishFinished($native, $exitCode, $status, $stoppedByUser, $finishedAt);
        }

        return new NativeBashObservationDTO(
            pid: $native->pid,
            pgid: $native->pgid,
            sessionId: $native->sessionId,
            command: $native->command,
            logPath: $native->logPath,
            statusPath: $native->statusPath,
            startedAt: $native->startedAt,
            finishedAt: $finishedAt,
            exitCode: $exitCode,
            stoppedByUser: $stoppedByUser,
            toolCallId: $native->toolCallId,
            backgroundRequested: $backgroundRequested,
            status: $status,
            deadlineNanoseconds: $native->deadlineNanoseconds,
        );
    }

    private function observeNativeAndPublish(BashProcessRecordDTO $record): void
    {
        if (isset($this->nativeLaunches[$record->statusPath])) {
            $this->observeNative($this->nativeLaunches[$record->statusPath]);

            return;
        }
        $exitCode = $this->lifecycle->readStatusFile($record->statusPath);
        if (null === $record->pid || null === $record->startedAt) {
            return;
        }
        $finished = null !== $exitCode || !$this->lifecycle->isAlive($record->pid);
        if (!$finished) {
            return;
        }
        $status = -1 === $exitCode
            ? BackgroundProcessStatusEnum::Stopped
            : (null !== $exitCode ? BackgroundProcessStatusEnum::Finished : BackgroundProcessStatusEnum::FinishedUnclean);
        // Timeout/stop intents mark Stopped before native exit evidence arrives.
        // Preserve that classification when the sidecar later reports completion,
        // matching ProcessStore::decide()'s settle path.
        if (BackgroundProcessStatusEnum::Stopped === $record->status) {
            $status = BackgroundProcessStatusEnum::Stopped;
        }
        $this->publishFinished(
            new NativeBashLaunchDTO(
                pid: $record->pid,
                pgid: $record->pgid,
                sessionId: $record->sessionId,
                command: $record->command,
                toolCallId: $record->toolCallId,
                deadlineNanoseconds: $record->deadlineNanoseconds,
                backgroundRequested: $record->backgroundRequested,
                logPath: $record->logPath,
                statusPath: $record->statusPath,
                startedAt: $record->startedAt,
            ),
            $exitCode,
            $status,
            $record->stoppedByUser || -1 === $exitCode,
            Clock::get()->now(),
        );
    }

    private function publishFinished(
        NativeBashLaunchDTO $native,
        ?int $exitCode,
        BackgroundProcessStatusEnum $status,
        bool $stoppedByUser,
        \DateTimeImmutable $finishedAt,
    ): void {
        $id = '' === $native->sessionId ? null : $this->store->recordIdForStatusPath($native->statusPath, $native->sessionId);
        if (null === $id || '' === $native->sessionId) {
            return;
        }
        $this->store->applyFinishedByRecord($id, $native->sessionId, $exitCode, $status, $stoppedByUser, $finishedAt);
    }

    private function releaseNativeLaunch(string $statusPath): void
    {
        unset($this->nativeLaunches[$statusPath]);
    }

    private function processDecision(int $recordId, string $runId, BashProcessActionEnum $action): ?BashProcessSnapshotDTO
    {
        return $this->store->decide($recordId, $runId, $action, $this->lifecycle);
    }

    /** Worker exit kills only unaccepted foreground launches it still owns. */
    private function reapUnacceptedNativeLaunches(): int
    {
        $count = 0;
        foreach ($this->nativeLaunches as $statusPath => $launch) {
            try {
                if (null !== $this->lifecycle->readStatusFile($launch->statusPath) || !$this->lifecycle->isAlive($launch->pid)) {
                    $this->releaseNativeLaunch($statusPath);
                    continue;
                }
                $this->stopByStatusPath($statusPath, '' !== $launch->sessionId ? $launch->sessionId : null);
                $this->releaseNativeLaunch($statusPath);
                ++$count;
            } catch (\Throwable $error) {
                $this->logger->error('background_process.native_reap_failed', [
                    'component' => 'tool.background_process',
                    'event_type' => 'background_process.native_reap_failed',
                    'session_id' => $launch->sessionId,
                    'run_id' => $launch->sessionId,
                    'status_path' => $statusPath,
                    'exception' => $error,
                ]);
            }
        }

        return $count;
    }

    // ─── Private helpers ─────────────────────────────────────────────
}
