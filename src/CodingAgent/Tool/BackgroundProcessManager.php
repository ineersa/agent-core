<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\CodingAgent\Application\BackgroundProcess\BashOwnershipStatusEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationClient;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationStatusEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashOwnershipRequestDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessDTO;
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

use function Amp\delay;

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
 *  3. On subsequent accepted-background list() calls, resolveEntityStatus() checks
 *     liveness via /proc/<pid> on Linux or the status file.
 *  4. stop() sends SIGTERM to the process group (negative PGID), polls
 *     until exit or the configured grace deadline, then SIGKILL if still alive.
 *
 * Session ownership: user-facing list(), readLogTail(), and
 * stop() operations require an owning session and expose only rows explicitly
 * accepted as background work. Instance shutdown resolves owned record IDs
 * directly and stops them through stopProcessEntity().
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
     * remains, and PID reuse can make fetchLatestByPid() resolve a foreign row.
     *
     * @var array<int, true>
     */
    private array $ownedRecordIds = [];

    private ?BashRegistrationClient $ownerClient = null;
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

    public function useLifecycleOwner(BashRegistrationClient $client): void
    {
        $this->ownerClient = $client;
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
                if (BashRegistrationStatusEnum::Registered !== $result->status || !$result->processExists || null === $result->recordId) {
                    throw new \RuntimeException('Bash registration refused: '.$result->status->value.([] !== $result->invalidFields ? ' ('.implode(', ', $result->invalidFields).')' : ''));
                }
                $dbId = $result->recordId;
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
                $this->reapOwnedLaunches();
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
     * List processes explicitly accepted as background work for one run.
     * Private foreground-supervision records (backgroundedAt === null) are
     * deliberately excluded from the user-facing bg_status surface.
     *
     * Resolves status from filesystem state (/proc/<pid> or status file)
     * and persists any changes before returning.
     *
     * @return list<BackgroundProcess>
     */
    public function list(string $sessionId): array
    {
        $entities = $this->store->fetchBackgrounded($sessionId);

        foreach ($entities as $entity) {
            $this->resolveEntityStatus($entity);
        }

        $this->store->flush();

        return $entities;
    }

    /**
     * Check whether a row exists for the given PID.
     *
     * Uses an ORM COUNT query that always hits the database, bypassing
     * the identity map. This allows callers to distinguish "row does not
     * exist" from "row exists but ORM/identity map did not return it."
     */
    public function existsByPid(int $pid): bool
    {
        return $this->store->existsByPid($pid);
    }

    /**
     * Check whether a row exists for the given record ID.
     *
     * Uses an ORM COUNT query that always hits the database, bypassing
     * the identity map.
     */
    public function existsByRecordId(int $id): bool
    {
        return $this->store->existsByRecordId($id);
    }

    /**
     * Find a single background process by PID, with refreshed status.
     *
     * Resolves status from filesystem state and flushes before returning.
     * This is O(1) per call (single entity fetch), unlike list() which
     * refreshes all entities. Preferred for polling loops that only care
     * about one known PID.
     *
     * Returns null for two distinct cases:
     *   1. The row does not exist (genuinely absent / cleaned up).
     *   2. The row exists but belongs to a different session (session
     *      mismatch — the entity is silently filtered out).
     *
     * Callers that need to distinguish these cases should use the DB
     * record id via findByRecordId() or call existsByPid() after a null
     * result from this method.
     *
     * @param int         $pid       Process PID to find (must be > 0)
     * @param string|null $sessionId optional session filter.
     *                               When provided, only a matching session
     *                               process is returned.
     *
     * @return BackgroundProcess|null the entity with refreshed status, or
     *                                null if not found or session mismatch
     *
     * PID resolution uses the newest retained row (ORDER BY id DESC) for public
     * bg_status semantics; session ownership is enforced after lookup
     */
    public function find(int $pid, ?string $sessionId = null): ?BackgroundProcess
    {
        $entity = $this->store->fetchLatestByPid($pid);

        if (null === $entity) {
            $this->logger->notice('background_process.find_by_pid_null', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.find_by_pid_null',
                'process_pid' => $pid,
                'session_id' => $sessionId ?? '',
                'reason' => 'fetch_by_pid_returned_null',
            ]);

            return null;
        }

        if (null !== $sessionId && $entity->sessionId !== $sessionId) {
            $this->logger->notice('background_process.find_by_pid_session_mismatch', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.find_by_pid_session_mismatch',
                'process_pid' => $pid,
                'session_id' => $sessionId,
                'entity_session_id' => $entity->sessionId,
                'reason' => 'find_by_pid_session_mismatch',
            ]);

            return null;
        }

        // Only flush when the entity transitioned from running to a
        // terminal state. If finishedAt was already set before the
        // resolve call, no mutation occurred; if it remains null, the
        // process is still running and no persisted column changed.
        // This avoids unnecessary DB writes during polling loops that
        // call find() every ~100 ms.
        $wasFinished = null !== $entity->finishedAt;
        $this->resolveEntityStatus($entity);

        if (!$wasFinished && null !== $entity->finishedAt) {
            $this->store->flush();
        }

        return $entity;
    }

    /**
     * Find a single background process by its auto-increment record ID,
     * with refreshed status.
     *
     * Unlike find() which queries by OS PID (which can be reused after
     * the process exits), this queries by the immutable auto-increment
     * primary key. Prefer this when the DB id is available (e.g. from
     * StartResult::$id returned by start()).
     *
     * Session scoping is preserved: if $sessionId is provided and the
     * entity belongs to a different session, null is returned.
     *
     * @param int         $id        Auto-increment record ID (> 0)
     * @param string|null $sessionId optional session filter.
     *                               When provided, only a matching session
     *                               process is returned.
     *
     * @return BackgroundProcess|null The entity with refreshed status, or
     *                                null if not found or session mismatch
     */
    public function findByRecordId(int $id, ?string $sessionId = null): ?BackgroundProcess
    {
        $entity = $this->store->fetchByRecordId($id);

        if (null === $entity) {
            $this->logger->notice('background_process.find_by_record_id_null', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.find_by_record_id_null',
                'record_id' => $id,
                'session_id' => $sessionId ?? '',
                'reason' => 'fetch_by_record_id_returned_null',
            ]);

            return null;
        }

        if (null !== $sessionId && $entity->sessionId !== $sessionId) {
            $this->logger->notice('background_process.find_by_record_id_session_mismatch', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.find_by_record_id_session_mismatch',
                'record_id' => $id,
                'session_id' => $sessionId,
                'entity_session_id' => $entity->sessionId,
                'reason' => 'find_by_record_id_session_mismatch',
            ]);

            return null;
        }

        $wasFinished = null !== $entity->finishedAt;
        $this->resolveEntityStatus($entity);

        if (!$wasFinished && null !== $entity->finishedAt) {
            $this->store->flush();
        }

        return $entity;
    }

    /**
     * Return a user-accepted background process log within its owning run.
     * The repository applies run, PID, and backgroundedAt predicates together,
     * so foreground and cross-run records are indistinguishable from absent.
     *
     * @throws \RuntimeException when no accepted process matches
     */
    public function readLogTail(string $sessionId, int $pid, ?int $maxChars = null): LogTailResult
    {
        $maxChars ??= $this->config->logTailChars;
        $entity = $this->store->fetchBackgroundedByPid($sessionId, $pid);

        if (null === $entity) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d for this session.', $pid));
        }

        $wasFinished = null !== $entity->finishedAt;
        $this->resolveEntityStatus($entity);
        if (!$wasFinished && null !== $entity->finishedAt) {
            $this->store->flush();
        }

        return $this->lifecycle->readLogTail($entity->logPath, $maxChars, null !== $entity->finishedAt);
    }

    /**
     * Return the tail of a background process log by immutable record ID.
     */
    public function readLogTailForRecord(int $recordId, ?int $maxChars = null, ?string $sessionId = null): LogTailResult
    {
        $maxChars ??= $this->config->logTailChars;

        $entity = $this->store->fetchByRecordId($recordId);

        if (null === $entity) {
            throw new \RuntimeException(\sprintf('No background process found with record ID %d.', $recordId));
        }

        if (null !== $sessionId && $entity->sessionId !== $sessionId) {
            throw new \RuntimeException(\sprintf('No background process found with record ID %d for this session.', $recordId));
        }

        $wasFinished = null !== $entity->finishedAt;
        $this->resolveEntityStatus($entity);
        if (!$wasFinished && null !== $entity->finishedAt) {
            $this->store->flush();
        }

        return $this->lifecycle->readLogTail($entity->logPath, $maxChars, null !== $entity->finishedAt);
    }

    /**
     * Stop a background process: TERM → grace → KILL.
     *
     * Targets the entire process group via negative PGID
     * (kill -TERM -<PGID>) when a PGID is available.  Falls back
     * to single-PID signalling only when the PGID could not be
     * determined — a rare race window immediately after process launch.
     *
     * @param int    $pid       Process PID to stop. Must be > 0.
     * @param string $sessionId owning run. Only an accepted background process
     *                          in that run can be stopped.
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

        $entity = $this->store->fetchBackgroundedByPid($sessionId, $pid);

        if (null === $entity) {
            throw new \RuntimeException(\sprintf('No background process found with PID %d for this session.', $pid));
        }

        return $this->stopProcessEntity($entity);
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

        $entity = $this->findByRecordId($recordId, $runId);
        if (null === $entity || $entity->toolCallId !== $toolCallId || null !== $entity->finishedAt || null !== $entity->backgroundedAt) {
            return false;
        }

        $entity->backgroundRequested = true;
        $this->store->flush();

        return true;
    }

    /**
     * Read-only selection snapshot. Native supervision and deadline enforcement
     * remain with the execution owner/poller, not TUI candidate discovery.
     *
     * @return list<BackgroundProcess>
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
        $entity = $this->store->fetchByRecordId($recordId);

        if (null === $entity) {
            throw new \RuntimeException(\sprintf('Background process record with ID %d not found.', $recordId));
        }

        if (null !== $sessionId && $entity->sessionId !== $sessionId) {
            throw new \RuntimeException(\sprintf('No background process with record ID %d for this session.', $recordId));
        }

        $entity->markBackgrounded(Clock::get()->now());
        $this->store->flush();

        $this->logger->info('background_process.marked_backgrounded', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.marked_backgrounded',
            'process_pid' => $entity->pid,
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

        $entity = $this->store->fetchByRecordId($recordId);

        if (null === $entity) {
            throw new \RuntimeException(\sprintf('No background process found with record ID %d.', $recordId));
        }

        if (null !== $sessionId && $entity->sessionId !== $sessionId) {
            throw new \RuntimeException(\sprintf('No background process found with record ID %d for this session.', $recordId));
        }

        return $this->stopProcessEntity($entity, $waitForExit);
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
     * Refresh unfinished entities for controller-owned runs in one batch.
     * Populate finished_at even when no list() call observes completion.
     *
     * Passive maintenance only: never signal a process. The owning controller
     * enforces accepted-job deadlines through refreshEntities(); maintenance
     * cannot share that owner's in-memory termination grace deadlines.
     *
     * Safe to call repeatedly — only queries unfinished entities, checks
     * status files and /proc/<pid>, and flushes any newly-resolved
     * entities. Already-finished entities are skipped.
     *
     * @param list<string> $sessionIds Controller-owned runs
     */
    public function refreshAllUnfinished(array $sessionIds): void
    {
        $entities = $this->store->fetchAllUnfinished($sessionIds);
        foreach ($entities as $entity) {
            $this->resolveEntityStatus($entity);
        }
        if ([] !== $entities) {
            $this->store->flush();
        }
    }

    /**
     * Resolve prefetched records, then persist the refresh phase once.
     * Newly requested timeout stops are durable before any TERM is sent.
     *
     * A caller may isolate individual resolution failures with onError. Flush
     * failures still propagate, so no completion is delivered before persistence.
     * Callers without onError retain fail-fast resolution.
     *
     * @param list<BackgroundProcess>                            $entities
     * @param \Closure(BackgroundProcess, \Throwable): void|null $onError
     *
     * @return list<BackgroundProcess> Successfully refreshed records in input order
     */
    public function refreshEntities(array $entities, ?\Closure $onError = null): array
    {
        // Acknowledging an explicit stop never releases controller supervision.
        // Its caller may die during grace or only request a nonblocking stop.
        foreach (array_keys($this->timeoutGraceDeadlines) as $id) {
            $record = $this->store->fetchByRecordId($id);
            if (null === $record || null !== $record->finishedAt) {
                unset($this->timeoutGraceDeadlines[$id]);
            }
        }

        $refreshed = [];
        $toTerminate = [];
        foreach ($entities as $entity) {
            try {
                $this->resolveEntityStatus($entity);
                $deadlineExpired = null !== $entity->deadlineNanoseconds && hrtime(true) >= $entity->deadlineNanoseconds;
                if (null !== $entity->finishedAt) {
                    unset($this->timeoutGraceDeadlines[$entity->id]);
                } elseif (null !== $entity->backgroundedAt && ($entity->stoppedByUser || $deadlineExpired)) {
                    // The batch flush records the stop cause before TERM can
                    // make cleanup exit. finishedAt still gates completion.
                    if (!isset($this->timeoutGraceDeadlines[$entity->id])) {
                        $entity->status = BackgroundProcessStatusEnum::Stopped;
                        if ($entity->stoppedByUser) {
                            // TERM was requested already. Adopt bounded grace
                            // without signaling cleanup subprocesses a second time.
                            $this->timeoutGraceDeadlines[$entity->id] = hrtime(true) + $this->config->stopGraceSeconds * 1_000_000_000;
                        } else {
                            $toTerminate[] = $entity;
                        }
                    }
                    if (isset($this->timeoutGraceDeadlines[$entity->id]) && hrtime(true) >= $this->timeoutGraceDeadlines[$entity->id]) {
                        $this->lifecycle->sendKill($entity->pid, $entity->pgid);
                        $entity->finish(null, Clock::get()->now());
                        $this->lifecycle->writeStopMarker($entity->statusPath);
                        unset($this->timeoutGraceDeadlines[$entity->id]);
                    }
                }
                $refreshed[] = $entity;
            } catch (\Throwable $e) {
                if (null === $onError) {
                    throw $e;
                }
                $onError($entity, $e);
            }
        }
        if ([] !== $refreshed) {
            $this->store->flush();
        }
        // Never wait for grace: all due processes receive TERM after the same
        // refresh flush, and later passes observe exit or escalate to KILL.
        $stoppedAfterTerm = false;
        foreach ($toTerminate as $entity) {
            try {
                // A separate caller may have requested an explicit stop since
                // the candidate snapshot. Refresh its intent before signaling.
                $current = $this->store->fetchByRecordId($entity->id);
                if (null === $current || null !== $current->finishedAt || $current->stoppedByUser) {
                    continue;
                }
                $this->lifecycle->sendTerm($entity->pid, $entity->pgid);
                $this->timeoutGraceDeadlines[$entity->id] = hrtime(true) + $this->config->stopGraceSeconds * 1_000_000_000;
                if (0 === $this->config->stopGraceSeconds) {
                    $this->lifecycle->sendKill($entity->pid, $entity->pgid);
                    $entity->markStopped(Clock::get()->now());
                    $entity->stoppedByUser = false;
                    $this->lifecycle->writeStopMarker($entity->statusPath);
                    unset($this->timeoutGraceDeadlines[$entity->id]);
                    $stoppedAfterTerm = true;
                }
                $this->logger->info('background_process.timed_out', [
                    'component' => 'tool.background_process',
                    'event_type' => 'background_process.timed_out',
                    'run_id' => $entity->sessionId,
                    'session_id' => $entity->sessionId,
                    'record_id' => $entity->id,
                ]);
            } catch (\Throwable $e) {
                if (null === $onError) {
                    throw $e;
                }
                $onError($entity, $e);
            }
        }
        // Zero-grace stops end after the cause was flushed but before delivery.
        if ($stoppedAfterTerm) {
            $this->store->flush();
        }

        return $refreshed;
    }

    /**
     * Shared stop lifecycle for a resolved background-process entity.
     */
    private function stopProcessEntity(BackgroundProcess $entity, bool $waitForExit = true): StopResult
    {
        unset($this->timeoutGraceDeadlines[$entity->id]);
        $pid = $entity->pid;

        // Refresh status before acting — the process may have finished
        // since the last list() call (status file written, /proc gone).
        $this->resolveEntityStatus($entity);
        $this->store->flush();

        if (null !== $entity->finishedAt) {
            return new StopResult(
                pgid: $entity->pgid,
                alreadyFinished: true,
                signalSent: 'none',
            );
        }

        $pgid = $entity->pgid;
        $graceSeconds = $this->config->stopGraceSeconds;

        // Persist explicit stop intent before TERM: an independent completion
        // poller can observe native exit while this caller is still in grace.
        $entity->status = BackgroundProcessStatusEnum::Stopped;
        $entity->stoppedByUser = true;
        $entity->markCompletionNotified(Clock::get()->now());
        $this->store->flush();

        // TERM signal — target process group (negative PGID)
        $signalSent = 'term';
        $this->lifecycle->sendTerm($pid, $pgid);
        if (!$waitForExit) {
            return new StopResult(pgid: $pgid, alreadyFinished: false, signalSent: $signalSent);
        }

        // Poll until cooperative exit or grace deadline, then KILL if still alive.
        // Wrapper trap writes .status before /proc fully disappears; treat that as done.
        // Yield through the grace period so sibling tools and lease renewal remain runnable.
        $statusPath = $entity->statusPath;
        if ($graceSeconds > 0) {
            $deadlineNs = hrtime(true) + ($graceSeconds * 1_000_000_000);
            while (hrtime(true) < $deadlineNs) {
                if (null !== $this->lifecycle->readStatusFile($statusPath)) {
                    break;
                }
                if (!$this->lifecycle->isAlive($pid)) {
                    break;
                }
                delay(0.05);
            }
        }

        // Prefer status-file proof of cooperative exit over /proc residual.
        $cooperativelyExited = null !== $this->lifecycle->readStatusFile($statusPath);
        if (!$cooperativelyExited && $this->lifecycle->isAlive($pid)) {
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
        if ('' !== $statusPath) {
            $this->lifecycle->writeStopMarker($statusPath);
        }

        $this->store->flush();

        return new StopResult(
            pgid: $pgid,
            alreadyFinished: false,
            signalSent: $signalSent,
        );
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
                $entity = $this->store->fetchByRecordId($recordId);
                if (null !== $entity) {
                    $this->stopProcessEntity($entity);
                    ++$count;
                }
                // Missing rows were already removed by session/provisional cleanup.
                // Keep ownership on failure so a later cleanup can retry.
                unset($this->ownedRecordIds[$recordId]);
            } catch (\RuntimeException $e) {
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

    private function reapOwnedLaunches(): int
    {
        $count = 0;
        foreach ($this->ownedLaunches as $identity => $launch) {
            try {
                $status = $this->ownerClient?->retire(new BashOwnershipRequestDTO($identity, $launch['owner']));
                if (BashOwnershipStatusEnum::Retired === $status) {
                    // Infrastructure retirement is not a user stop and must not
                    // suppress completion delivery by marking it notified.
                    // A completed wrapper's PID may already have been reused.
                    // Its immutable sidecar is stronger evidence than liveness.
                    if (null !== $this->lifecycle->readStatusFile($launch['status']) || !$this->lifecycle->isAlive($launch['pid'])) {
                        unset($this->ownedLaunches[$identity]);
                        continue;
                    }
                    $this->lifecycle->sendTerm($launch['pid'], $launch['pgid']);
                    $deadline = hrtime(true) + $this->config->stopGraceSeconds * 1_000_000_000;
                    while (null === $this->lifecycle->readStatusFile($launch['status']) && $this->lifecycle->isAlive($launch['pid']) && hrtime(true) < $deadline) {
                        delay(0.05);
                    }
                    if (null === $this->lifecycle->readStatusFile($launch['status']) && $this->lifecycle->isAlive($launch['pid'])) {
                        $this->lifecycle->sendKill($launch['pid'], $launch['pgid']);
                    }
                    ++$count;
                } elseif (BashOwnershipStatusEnum::Accepted !== $status) {
                    throw new \RuntimeException('Bash retirement refused: '.($status->value ?? 'no_owner'));
                }
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
    private function resolveEntityStatus(BackgroundProcess $entity): void
    {
        // Already resolved in DB
        if (null !== $entity->finishedAt) {
            return;
        }

        $pid = $entity->pid;

        // Check status file first (process may have finished normally)
        $exitCode = $this->lifecycle->readStatusFile($entity->statusPath);
        if (null !== $exitCode) {
            $now = Clock::get()->now();
            $entity->finish($exitCode, $now);

            return;
        }

        // Check /proc/<pid> for liveness
        if ($this->lifecycle->isAlive($pid)) {
            if (BackgroundProcessStatusEnum::Stopped !== $entity->status) {
                $entity->status = BackgroundProcessStatusEnum::Running;
            }

            return;
        }

        // Process is gone but no status file written (crash / SIGKILL / unclean exit)
        $now = Clock::get()->now();
        $entity->markFinishedUnclean($now);

        $this->logger->info('background_process.finished_unclean', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.finished_unclean',
            'process_pid' => $pid,
        ]);
    }
}
