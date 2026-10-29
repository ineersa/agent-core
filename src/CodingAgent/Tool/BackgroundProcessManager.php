<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\CodingAgent\Application\BackgroundProcess\BashOwnershipStatusEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashProcessActionEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationClient;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationStatusEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashOwnershipRequestDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessSnapshotDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RequestBashBackgroundDTO;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\BashProcessRecordDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\ForegroundBashProcessDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\LogTailResult;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StartResult;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StopResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

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

    /**
     * Immutable record IDs launched via start() by THIS instance.
     *
     * Shutdown cleanup must address the exact rows this PHP process created.
     * Tracking OS PIDs alone is unsafe: rows can be deleted while ownership
     * remains, and PID reuse can make latestSnapshot() resolve a foreign row.
     *
     * @var array<int, true>
     */
    private array $ownedRecordIds = [];

    private ?BashRegistrationClient $ownerClient = null;
    private ?BashRegistrationClient $processOwnerClient = null;
    /** @var array<string, array{pid: int, pgid: ?int, status: string, owner: string}> */
    private array $ownedLaunches = [];
    /** @var array<int, string> */
    private array $launchIdentities = [];

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

    public function useProcessOwner(BashRegistrationClient $client): void
    {
        $this->processOwnerClient = $client;
    }

    public function useLifecycleOwner(BashRegistrationClient $client): void
    {
        $this->ownerClient = $client;
        $this->processOwnerClient = $client;
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
    public function start(string $command, ?string $sessionId = null, ?string $toolCallId = null, ?int $deadlineNanoseconds = null, bool $backgroundRequested = false): StartResult
    {
        $owner = null;
        if (null !== $this->ownerClient) {
            $owner = $this->store->ownerSessionForRun($sessionId ?? '');
            if (null === $owner) {
                throw new \RuntimeException('Bash launch has no retained owning session.');
            }
        }
        // Ensure storage directory exists
        $bgDir = $this->lifecycle->ensureStorageDir();

        // Generate unique file prefix and file paths
        $filePrefix = bin2hex(random_bytes(16));
        $pidFile = $bgDir.'/'.$filePrefix.'.pid';
        $statusFile = $bgDir.'/'.$filePrefix.'.status';
        $logFile = $bgDir.'/'.$filePrefix.'.log';

        $now = Clock::get()->now();

        // Launch process in new session group
        $launchResult = $this->lifecycle->launchProcess($command, $pidFile, $logFile, $statusFile);
        $pid = $launchResult['pid'];
        $pgid = $launchResult['pgid'];

        // Persist launch intent with the initial row; acknowledgement still belongs to the supervisor.
        try {
            if (null !== $this->ownerClient) {
                // Remember the captured launch before publication. A send failure
                // does not tell us whether registration committed remotely.
                $this->ownedLaunches[$filePrefix] = ['pid' => $pid, 'pgid' => $pgid, 'status' => $statusFile, 'owner' => $owner];
                $result = $this->ownerClient->register(new RegisterBashProcessDTO(
                    $filePrefix, $owner, $sessionId ?? '', $pid, $pgid,
                    $command, $toolCallId, $deadlineNanoseconds, $backgroundRequested,
                    $logFile, $statusFile, $now,
                ));
                if (BashRegistrationStatusEnum::Registered === $result->status && !$result->processExists) {
                    // Provisional cleanup already retired this completed launch.
                    // Its old PID is no longer safe to signal after reply loss.
                    unset($this->ownedLaunches[$filePrefix]);
                }
                if (BashRegistrationStatusEnum::Registered !== $result->status || !$result->processExists || null === $result->recordId || null === $result->logPath) {
                    throw new \RuntimeException('Bash registration refused: '.$result->status->value.([] !== $result->invalidFields ? ' ('.implode(', ', $result->invalidFields).')' : ''));
                }
                $dbId = $result->recordId;
                $logFile = $result->logPath;
                $this->launchIdentities[$dbId] = $filePrefix;
            } else {
                $dbId = $this->store->insertRecord([
                    'pid' => $pid,
                    'pgid' => $pgid,
                    'session_id' => $sessionId ?? '',
                    'command' => $command,
                    'tool_call_id' => $toolCallId,
                    'deadline_nanoseconds' => $deadlineNanoseconds,
                    'background_requested' => $backgroundRequested,
                    'log_path' => $logFile,
                    'status_path' => $statusFile,
                    'started_at' => $now,
                ]);
            }
        } catch (\Throwable $exception) {
            if (null === $this->ownerClient) {
                // Local insert failures have a known outcome.
                $this->lifecycle->sendKill($pid, $pgid);
            } else {
                // Only an authoritative retirement grants permission to kill.
                // Unknown outcomes retain ownership for shutdown reconciliation.
                $this->reapOwnedLaunches($filePrefix);
            }
            throw $exception;
        }

        // Track for instance-scoped shutdown only after the durable row exists.
        // Ownership is the immutable record ID, never the reusable OS PID.
        $this->ownedRecordIds[$dbId] = true;

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
            logPath: $logFile,
        );
    }

    /**
     * Only accepted background records are public. Private foreground
     * supervision must never appear on bg_status's list surface.
     *
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

    /** Scalar COUNT bypasses ORM identity-map state, including retained aliases. */
    public function existsByPid(int $pid): bool
    {
        return $this->store->existsByPid($pid);
    }

    /** Scalar COUNT distinguishes a missing row from a filtered lookup. */
    public function existsByRecordId(int $id): bool
    {
        return $this->store->existsByRecordId($id);
    }

    /**
     * Public PID lookup selects the newest retained row before filtering
     * ownership. Null covers both absence and a different session; callers
     * needing to distinguish them can query existsByPid(). Immutable record
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
        // Observations belong to the metadata owner. Neither this read nor an
        // awaited reply retains a managed entity from the caller's UnitOfWork.
        if (null === $record->finishedAt) {
            $this->processDecision($id, $record->sessionId, BashProcessActionEnum::Observe);

            return $this->store->snapshot($id);
        }

        return $record;
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
     * Mark the exact background-process record as user-backgrounded.
     *
     * Used by BashTool when its worker accepts the background request for the
     * current invocation (immutable record ID from start()).
     *
     * @throws \RuntimeException when record not found or session mismatches
     */
    public function markBackgroundedForRecord(int $recordId, ?string $sessionId = null): void
    {
        if (null !== $this->ownerClient && isset($this->launchIdentities[$recordId])) {
            if (!$this->tryMarkBackgroundedForRecord($recordId, $sessionId)) {
                throw new \RuntimeException('Bash process finished before background acceptance.');
            }

            return;
        }
        if (null !== $this->processOwnerClient) {
            throw new \RuntimeException('Bash background acceptance requires this worker\'s registered launch identity.');
        }
        $record = $this->store->snapshot($recordId);

        if (null === $record) {
            throw new \RuntimeException(\sprintf('Background process record with ID %d not found.', $recordId));
        }

        if (null !== $sessionId && $record->sessionId !== $sessionId) {
            throw new \RuntimeException(\sprintf('No background process with record ID %d for this session.', $recordId));
        }

        if (!$this->store->acceptLocal($recordId, $sessionId)) {
            throw new \RuntimeException('Bash background acceptance record/session mismatch.');
        }

        $this->logger->info('background_process.marked_backgrounded', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.marked_backgrounded',
            'process_pid' => $record->pid,
            'record_id' => $recordId,
            'process_session_id' => $sessionId ?? '',
        ]);
    }

    /** A completion committed before acceptance belongs to the foreground result. */
    public function tryMarkBackgroundedForRecord(int $recordId, ?string $sessionId = null): bool
    {
        if (null === $this->ownerClient || !isset($this->launchIdentities[$recordId])) {
            $this->markBackgroundedForRecord($recordId, $sessionId);

            return true;
        }
        $recordSession = $this->store->sessionForRecord($recordId);
        if (null === $recordSession || (null !== $sessionId && $recordSession !== $sessionId)) {
            throw new \RuntimeException('Bash background acceptance record/session mismatch.');
        }
        $identity = $this->launchIdentities[$recordId];
        $status = $this->ownerClient->accept(new BashOwnershipRequestDTO($identity, $this->ownedLaunches[$identity]['owner']));
        if (BashOwnershipStatusEnum::ProcessFinished === $status) {
            return false;
        }
        if (BashOwnershipStatusEnum::Accepted !== $status) {
            throw new \RuntimeException('Bash background acceptance refused: '.$status->value);
        }

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
     * This PHP-shutdown fallback queries only this instance's immutable record
     * IDs. The controller lifecycle listener owns session-wide cleanup.
     *
     * @return int Number of processes terminated
     */
    public function shutdownCleanup(): int
    {
        return $this->reapOwnedProcesses();
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
            if (null !== $decision && !$decision->finished) {
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

    private function processDecision(int $recordId, string $runId, BashProcessActionEnum $action): ?BashProcessSnapshotDTO
    {
        return null !== $this->processOwnerClient
            ? $this->processOwnerClient->decide($recordId, $runId, $action)
            : $this->store->decide($recordId, $runId, $action, $this->lifecycle);
    }

    /**
     * Reap only records started via start() on this manager instance.
     *
     * Used when shutdownCleanup() is called with no session id (PHP shutdown
     * handler path). Never reads unfinished rows from the shared database.
     */
    private function reapOwnedProcesses(): int
    {
        $count = $this->reapOwnedLaunches();
        foreach (array_keys($this->ownedRecordIds) as $recordId) {
            if (isset($this->launchIdentities[$recordId])) {
                unset($this->ownedRecordIds[$recordId]);
                continue;
            }
            try {
                $runId = $this->store->sessionForRecord($recordId);
                if (null !== $runId) {
                    $this->stopProcessRecord($recordId, $runId);
                    ++$count;
                }
                // Missing rows were already removed by session/provisional cleanup.
                // Keep ownership on failure so a later cleanup can retry.
                unset($this->ownedRecordIds[$recordId]);
            } catch (\Throwable $e) {
                $this->logger->warning('background_process.shutdown_cleanup_error', [
                    'component' => 'tool.background_process',
                    'event_type' => 'background_process.shutdown_cleanup_error',
                    'record_id' => $recordId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    private function reapOwnedLaunches(?string $onlyIdentity = null): int
    {
        $count = 0;
        foreach ($this->ownedLaunches as $identity => $launch) {
            if (null !== $onlyIdentity && $identity !== $onlyIdentity) {
                continue;
            }
            try {
                $count += $this->lifecycle->withSidecarLock($launch['status'], function () use ($identity, $launch): int {
                    $status = $this->ownerClient?->retire(new BashOwnershipRequestDTO($identity, $launch['owner']));
                    if (BashOwnershipStatusEnum::Retired === $status) {
                        // Infrastructure retirement is not a user stop and must not
                        // suppress completion delivery by marking it notified.
                        // Hold the sidecar lock across the owner round trip: deletion
                        // after its reply cannot erase evidence before signalling.
                        if (null !== $this->lifecycle->readStatusFile($launch['status']) || !$this->lifecycle->isAlive($launch['pid'])) {
                            return 0;
                        }
                        $this->lifecycle->sendTerm($launch['pid'], $launch['pgid']);
                        $deadline = hrtime(true) + $this->config->stopGraceSeconds * 1_000_000_000;
                        while (null === $this->lifecycle->readStatusFile($launch['status']) && $this->lifecycle->isAlive($launch['pid']) && hrtime(true) < $deadline) {
                            delay(0.05);
                        }
                        if (null === $this->lifecycle->readStatusFile($launch['status']) && $this->lifecycle->isAlive($launch['pid'])) {
                            $this->lifecycle->sendKill($launch['pid'], $launch['pgid']);
                        }

                        return 1;
                    }
                    if (!\in_array($status, [BashOwnershipStatusEnum::Accepted, BashOwnershipStatusEnum::ProcessMissing, BashOwnershipStatusEnum::ProcessFinished], true)) {
                        throw new \RuntimeException('Bash retirement refused: '.($status->value ?? 'no_owner'));
                    }

                    return 0;
                });
                unset($this->ownedLaunches[$identity]);
            } catch (\Throwable $error) {
                $this->logger->error('background_process.retirement_unknown', [
                    'component' => 'tool.background_process',
                    'event_type' => 'background_process.retirement_unknown',
                    'session_id' => $launch['owner'],
                    'run_id' => $launch['owner'],
                    'exception' => $error,
                ]);
            }
        }

        return $count;
    }

    // ─── Private helpers ─────────────────────────────────────────────
}
