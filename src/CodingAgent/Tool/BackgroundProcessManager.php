<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\CodingAgent\Application\BackgroundProcess\BashProcessActionEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessSnapshotDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RequestBashBackgroundDTO;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\BashProcessRecordDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\ForegroundBashProcessDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\NativeBashLaunchDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\NativeBashObservationDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\PreparedBashLaunchDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\LogTailResult;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StartResult;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StopResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Messenger\MessageBusInterface;

use function Amp\delay;

/**
 * Native process supervision with owner-backed durable metadata.
 *
 * This is a facade that delegates database operations to ProcessStore
 * and OS/filesystem operations to ProcessLifecycle.
 *
 * Process lifecycle:
 *  1. start() launches the command in a new session/process group via setsid,
 *     then registers its immutable launch identity with the metadata owner. A shell
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
 * accepted as background work. Instance shutdown resolves owned record IDs
 * directly and stops them through stopProcessRecord().
 *
 * Shutdown handling:
 * Call registerShutdownHandler() from production wiring (services.yaml)
 * to register a PHP shutdown function that calls shutdownCleanup() with
 * no session argument. That path reaps only background-process records this
 * PHP instance started (owned record IDs), not every unfinished row in the
 * shared database. This covers graceful exit (Ctrl+C, quit command,
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

    private ?MessageBusInterface $commandBus = null;
    private bool $remoteLifecycleOwner = false;
    /** @var array<string, NativeBashLaunchDTO> statusPath => native launch */
    private array $nativeLaunches = [];

    /** @var array<int, int> Immutable record ID to monotonic TERM grace deadline. */
    private array $timeoutGraceDeadlines = [];

    public function __construct(
        private readonly ProcessStore $store,
        private readonly ProcessLifecycle $lifecycle,
        private readonly BackgroundProcessConfig $config,
        private readonly LoggerInterface $logger,
        ?MessageBusInterface $commandBus = null,
    ) {
        $this->commandBus = $commandBus;
        // Shutdown handler is NOT registered here. Call
        // registerShutdownHandler() from production wiring
        // (config/services.yaml) to enable automatic cleanup.
        // This avoids accumulating shutdown callbacks in tests
        // where many manager instances are constructed.
    }

    /**
     * Tool workers publish one-way process intents; they must not write SQL.
     * Controllers and schedulers use the same remote mode after exclusive
     * startup cleanup so only run_control mutates durable process state.
     */
    public function useRemoteLifecycleOwner(): void
    {
        $this->remoteLifecycleOwner = true;
    }

    /**
     * Session lifecycle cleanup runs after every consumer has stopped.
     * Finished-row deletion must write locally; one-way remote commands cannot
     * prove the delete completed before the controller exits.
     */
    public function useLocalLifecycleOwner(): void
    {
        $this->remoteLifecycleOwner = false;
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
     * Tool workers also construct this manager. Their owner-backed retirement
     * decision fences delayed acceptance before foreground cleanup signals.
     * Accepted jobs belong to the controller, not the retiring worker.
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
     * Owner-prepared native identity for a top-level model Bash or direct shell call.
     *
     * Allocates sidecar paths once and reuses them for the same run/toolCallId.
     * PID and started_at remain null until local launch reports them.
     */
    public function prepareNativeLaunch(
        string $runId,
        string $toolCallId,
        string $command,
        bool $backgroundRequested = false,
    ): PreparedBashLaunchDTO {
        if ('' === $runId || '' === $toolCallId) {
            throw new \InvalidArgumentException('Native Bash preparation requires run and tool-call identity.');
        }
        $command = trim($command);
        if ('' === $command) {
            throw new \InvalidArgumentException('Native Bash preparation requires a non-empty command.');
        }

        $existing = $this->store->findPreparedByRunAndToolCall($runId, $toolCallId);
        if (null !== $existing) {
            return new PreparedBashLaunchDTO(
                id: $existing->id,
                runId: $existing->sessionId,
                toolCallId: (string) $existing->toolCallId,
                command: $existing->command,
                logPath: $existing->logPath,
                statusPath: $existing->statusPath,
                deferredId: null,
                deadlineNanoseconds: null,
            );
        }

        $bgDir = $this->lifecycle->ensureStorageDir();
        $prefix = bin2hex(random_bytes(16));
        $logPath = $bgDir.'/'.$prefix.'.log';
        $statusPath = $bgDir.'/'.$prefix.'.status';
        $id = $this->store->prepareRecord([
            'session_id' => $runId,
            'command' => $command,
            'tool_call_id' => $toolCallId,
            'log_path' => $logPath,
            'status_path' => $statusPath,
            'background_requested' => $backgroundRequested,
        ]);

        return new PreparedBashLaunchDTO(
            id: $id,
            runId: $runId,
            toolCallId: $toolCallId,
            command: $command,
            logPath: $logPath,
            statusPath: $statusPath,
            deferredId: null,
            deadlineNanoseconds: null,
        );
    }

    public function findPreparedLaunch(string $runId, string $toolCallId): ?PreparedBashLaunchDTO
    {
        $existing = $this->store->findPreparedByRunAndToolCall($runId, $toolCallId);
        if (null === $existing || null === $existing->toolCallId) {
            return null;
        }

        return new PreparedBashLaunchDTO(
            id: $existing->id,
            runId: $existing->sessionId,
            toolCallId: $existing->toolCallId,
            command: $existing->command,
            logPath: $existing->logPath,
            statusPath: $existing->statusPath,
            deferredId: null,
            deadlineNanoseconds: $existing->deadlineNanoseconds,
        );
    }

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
        ?int $preparedRecordId = null,
        ?string $preparedLogPath = null,
        ?string $preparedStatusPath = null,
        ?string $deferredId = null,
    ): StartResult {
        $resolvedSessionId = $sessionId ?? '';
        $usingPrepared = null !== $preparedRecordId
            && null !== $preparedLogPath
            && null !== $preparedStatusPath
            && '' !== $resolvedSessionId
            && null !== $toolCallId;

        if ($usingPrepared) {
            $logFile = $preparedLogPath;
            $statusFile = $preparedStatusPath;
            $pidFile = preg_replace('/\.status$/', '.pid', $statusFile) ?? ($statusFile.'.pid');
            $recordId = $preparedRecordId;
        } else {
            // Nested/local launches remain unpersisted: allocate ephemeral paths.
            $bgDir = $this->lifecycle->ensureStorageDir();
            $filePrefix = bin2hex(random_bytes(16));
            $pidFile = $bgDir.'/'.$filePrefix.'.pid';
            $statusFile = $bgDir.'/'.$filePrefix.'.status';
            $logFile = $bgDir.'/'.$filePrefix.'.log';
            $recordId = null;
        }

        $now = Clock::get()->now();
        $launchResult = $this->lifecycle->launchProcess($command, $pidFile, $logFile, $statusFile);
        $pid = $launchResult['pid'];
        $pgid = $launchResult['pgid'];
        $native = new NativeBashLaunchDTO(
            pid: $pid,
            pgid: $pgid,
            sessionId: $resolvedSessionId,
            command: $command,
            toolCallId: $toolCallId,
            deadlineNanoseconds: $deadlineNanoseconds,
            backgroundRequested: $backgroundRequested,
            logPath: $logFile,
            statusPath: $statusFile,
            startedAt: $now,
            recordId: $recordId,
            deferredId: $deferredId,
        );
        $this->nativeLaunches[$statusFile] = $native;

        // Prepared rows stay owner-owned. Nested/unscoped launches stay local and
        // unpersisted; nativeLaunches alone tracks live local foreground handles.

        $this->logger->info('background_process.started', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.started',
            'process_pid' => $pid,
            'process_pgid' => $pgid,
            'has_group_termination' => true,
            'log_path' => $logFile,
            'status_path' => $statusFile,
            'process_session_id' => $resolvedSessionId,
            'record_id' => $recordId,
        ]);

        return new StartResult(
            pid: $pid,
            logPath: $logFile,
            statusPath: $statusFile,
            id: $recordId,
            deferredId: $deferredId,
        );
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
     * (kill -TERM -<PGID>) when a PGID is available.  Falls back
     * to single-PID signalling only when the PGID could not be
     * determined — a rare race window immediately after process launch.
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

        return $this->store->fetchForegroundTools($runId);
    }

    /**
     * Resolve a still-running background acceptance candidate.
     *
     * Callers must perform the freshest cancellation check after this returns
     * and before {@see acceptResolvedBackground()}, with no yield between them.
     *
     * @return array{kind: 'native', statusPath: string, recordId: int, pid: int, sessionId: string, deferredId: ?string}|array{kind: 'record', statusPath: string, recordId: int, pid: ?int, sessionId: string}|null
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
            $id = $native->recordId ?? (
                '' === $observed->sessionId ? null : $this->store->recordIdForStatusPath($statusPath, $observed->sessionId)
            );
            if (null === $id) {
                throw new \RuntimeException('Bash background acceptance record/session mismatch.');
            }

            return [
                'kind' => 'native',
                'statusPath' => $statusPath,
                'recordId' => $id,
                'pid' => $observed->pid,
                'sessionId' => $sessionId ?? $observed->sessionId,
                'deferredId' => $native->deferredId,
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
     * @param array{kind: 'native', statusPath: string, recordId: int, pid: int, sessionId: string, deferredId: ?string}|array{kind: 'record', statusPath: string, recordId: int, pid: ?int, sessionId: string} $candidate
     */
    public function acceptResolvedBackground(array $candidate): bool
    {
        $statusPath = $candidate['statusPath'];
        if ('native' === $candidate['kind']) {
            // Model Bash persists acceptance through ordinary ToolResult on the owner.
            // Local/controller paths still write immediately.
            if (null === $candidate['deferredId']) {
                if (!$this->store->acceptLocal($candidate['recordId'], $candidate['sessionId'])) {
                    throw new \RuntimeException('Bash background acceptance record/session mismatch.');
                }
            }
            $this->logger->info('background_process.marked_backgrounded', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.marked_backgrounded',
                'process_pid' => $candidate['pid'],
                'status_path' => $statusPath,
                'process_session_id' => $candidate['sessionId'],
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
        ]);
        $this->releaseNativeLaunch($statusPath);

        return true;
    }

    /**
     * Stop a background process by immutable record ID.
     *
     * Same TERM → grace → KILL lifecycle as stop(), but targets the exact
     * row tracked by a foreground BashTool invocation.
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
            $this->lifecycle->sendTerm($native->pid, $native->pgid);
            if (!$waitForExit) {
                return new StopResult(pgid: $native->pgid, alreadyFinished: false, signalSent: 'TERM');
            }
            $deadline = hrtime(true) + $this->config->stopGraceSeconds * 1_000_000_000;
            while (null === $this->lifecycle->readStatusFile($statusPath) && $this->lifecycle->isAlive($native->pid) && hrtime(true) < $deadline) {
                delay(0.05);
            }
            $signalSent = 'TERM';
            if (null === $this->lifecycle->readStatusFile($statusPath) && $this->lifecycle->isAlive($native->pid)) {
                $this->lifecycle->sendKill($native->pid, $native->pgid);
                $signalSent = 'KILL';
            }
            $this->lifecycle->writeStopMarker($statusPath);
            $this->observeNative($native);

            return new StopResult(pgid: $native->pgid, alreadyFinished: false, signalSent: $signalSent);
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

    /**
     * Refresh unfinished snapshots for controller-owned runs.
     * Populate finished_at even when no list() call observes completion.
     *
     * Passive maintenance only: never signal a process. The owning controller
     * enforces accepted-job deadlines through refreshRecords(); maintenance
     * cannot share that owner's in-memory termination grace deadlines.
     *
     * Only the owner records observed completion. No deadline or signal
     * decisions are made by this passive maintenance path.
     *
     * @param list<string> $sessionIds Controller-owned runs
     */
    public function refreshAllUnfinished(array $sessionIds): void
    {
        foreach ($this->store->unfinishedSnapshots($sessionIds) as $record) {
            $this->processDecision($record->id, $record->sessionId, BashProcessActionEnum::Observe);
        }
    }

    /**
     * Remove only finished metadata and its exact sidecars. Finished remote
     * launches cannot accept background ownership. Local acceptance has no
     * suspension between this locked recheck and deletion. Owner decisions do
     * not acquire this sidecar lock, so waiting for the reply cannot deadlock.
     */
    public function cleanupFinishedRecord(int $recordId, string $runId, bool $provisional = false): bool
    {
        $record = $this->store->snapshot($recordId);
        if (null === $record || $record->sessionId !== $runId) {
            return false;
        }

        return $this->lifecycle->withSidecarLock($record->statusPath, function () use ($recordId, $runId, $provisional): bool {
            $record = $this->store->snapshot($recordId);
            if (null === $record || $record->sessionId !== $runId || null === $record->finishedAt
                || ($provisional && (null !== $record->backgroundedAt || $record->finishedAt > Clock::get()->now()->modify('-'.ProcessStore::PROVISIONAL_RETENTION_SECONDS.' seconds')))) {
                return false;
            }
            if (!$this->lifecycle->deleteLockedRecordSidecars($record->logPath, $record->statusPath)) {
                return false;
            }

            return null !== $this->processDecision($recordId, $runId, $provisional ? BashProcessActionEnum::DeleteProvisional : BashProcessActionEnum::DeleteFinished);
        });
    }

    /**
     * Native controller supervision uses snapshots; every durable transition
     * rereads current authority in the owner before any signal is sent.
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
            $this->lifecycle->sendTerm($decision->pid, $decision->pgid);
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
                $this->lifecycle->sendKill($decision->pid, $decision->pgid);
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
                $this->lifecycle->sendTerm($pid, $pgid);
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
                $this->lifecycle->sendKill($pid, $pgid);
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
        $backgroundRequested = $native->backgroundRequested;
        if ('' !== $native->sessionId) {
            $id = $this->store->recordIdForStatusPath($native->statusPath, $native->sessionId);
            if (null !== $id) {
                $stored = $this->store->snapshot($id);
                if (null !== $stored) {
                    $backgroundRequested = $stored->backgroundRequested || $backgroundRequested;
                }
            }
        }
        if ($finished) {
            if (-1 === $exitCode) {
                $status = BackgroundProcessStatusEnum::Stopped;
                $stoppedByUser = true;
            } elseif (null !== $exitCode) {
                $status = BackgroundProcessStatusEnum::Finished;
            } else {
                $status = BackgroundProcessStatusEnum::FinishedUnclean;
            }
            $finishedAt = Clock::get()->now();
            if (null === $native->deferredId) {
                $this->publishFinished($native, $exitCode, $status, $stoppedByUser, $finishedAt);
            }
            $this->releaseNativeLaunch($native->statusPath);
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
        $id = $native->recordId;
        if (null === $id && '' !== $native->sessionId) {
            $id = $this->store->recordIdForStatusPath($native->statusPath, $native->sessionId);
        }
        if (null === $id || '' === $native->sessionId) {
            return;
        }
        if ($this->remoteLifecycleOwner) {
            // Observation remains local; only the owner may persist completion.
            $this->processDecision($id, $native->sessionId, BashProcessActionEnum::Observe);

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
        if ($this->remoteLifecycleOwner) {
            if (null === $this->commandBus) {
                throw new \RuntimeException('Remote Bash lifecycle owner requires the command bus.');
            }
            $this->commandBus->dispatch(new BashProcessCommandDTO($recordId, $runId, $action));
            // Owner DecideBashProcessHandler alone mutates SQL. Callers keep a
            // read-only snapshot for native signalling when the row is local.
            $snapshot = $this->store->snapshot($recordId);
            if (null === $snapshot || $snapshot->sessionId !== $runId) {
                return null;
            }
            $finished = null !== $snapshot->finishedAt;
            $pid = $snapshot->pid;
            if (null === $pid || $pid <= 0) {
                $pid = $this->lifecycle->readPidFile($snapshot->statusPath);
            }
            $signalRequired = !$finished && (
                BashProcessActionEnum::Stop === $action
                || BashProcessActionEnum::Timeout === $action
            );

            return new BashProcessSnapshotDTO(
                $recordId,
                $runId,
                $pid,
                $snapshot->pgid,
                $snapshot->statusPath,
                $finished,
                $snapshot->status,
                $signalRequired && null !== $pid,
            );
        }

        // In-process owner/tests write durable decisions locally.
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
                $this->lifecycle->sendTerm($launch->pid, $launch->pgid);
                $deadline = hrtime(true) + $this->config->stopGraceSeconds * 1_000_000_000;
                while (null === $this->lifecycle->readStatusFile($launch->statusPath) && $this->lifecycle->isAlive($launch->pid) && hrtime(true) < $deadline) {
                    delay(0.05);
                }
                if (null === $this->lifecycle->readStatusFile($launch->statusPath) && $this->lifecycle->isAlive($launch->pid)) {
                    $this->lifecycle->sendKill($launch->pid, $launch->pgid);
                }
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
