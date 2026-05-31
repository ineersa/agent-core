<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tool\BackgroundProcess\BackgroundProcessRecord;
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
 * Lifecycle: start → list/readLogTail → stop (TERM → grace → KILL) → cleanup.
 *
 * Every process stores an optional session_id for ownership scoping.
 * BgStatusTool resolves the current session from StackToolExecutionContextAccessor.
 *
 * Schema is managed by Doctrine migrations — no runtime DDL.
 * Migrations run once at AgentCommand startup before any manager call.
 *
 * setsid is required. If unavailable, start() fails rather than
 * falling back to unsafe single-PID mode.
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
    }

    /**
     * Register a PHP shutdown function that terminates all running
     * background processes when this PHP process exits.
     *
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
     * and wrapped in a shell harness with log/pid/status sidecars.
     * SIGTERM is forwarded to the child process via trap.
     *
     * @param string      $command   Shell command to run in background.
     *                               Must be shell-escaped if it contains
     *                               user-controlled tokens.
     * @param string|null $sessionId optional session/run identifier for
     *                               ownership scoping
     *
     * @throws \RuntimeException on storage/launch failure
     */
    public function start(string $command, ?string $sessionId = null): StartResult
    {
        $bgDir = $this->lifecycle->ensureStorageDir();

        $filePrefix = bin2hex(random_bytes(8));
        $pidFile = $bgDir.'/'.$filePrefix.'.pid';
        $statusFile = $bgDir.'/'.$filePrefix.'.status';
        $logFile = $bgDir.'/'.$filePrefix.'.log';

        $now = Clock::get()->now()->format('c');

        $launchResult = $this->lifecycle->launchProcess($command, $pidFile, $logFile, $statusFile);
        $pid = $launchResult['pid'];
        $pgid = $launchResult['pgid'];

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
            status: BackgroundProcessStatusEnum::Running->value,
        );
    }

    /**
     * List all tracked background processes with refreshed status.
     *
     * @param string|null $sessionId optional session filter
     *
     * @return list<BackgroundProcessRecord>
     */
    public function list(?string $sessionId = null): array
    {
        $entities = $this->store->fetchAll($sessionId);

        $results = [];
        foreach ($entities as $entity) {
            $status = $this->resolveEntityStatus($entity);
            $results[] = $entity->toRecord($status);
        }

        $this->store->flush();

        return $results;
    }

    /**
     * Return the tail of a background process log file.
     *
     * @param int         $pid       Process PID
     * @param int|null    $maxChars  Maximum characters to return (null uses config default)
     * @param string|null $sessionId Optional session ownership check
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
     * Targets the entire process group via negative PGID when available.
     *
     * @param int         $pid       Process PID to stop. Must be > 0.
     * @param string|null $sessionId optional session ownership check
     *
     * @throws \RuntimeException when process not found, session mismatch, or PID invalid
     */
    public function stop(int $pid, ?string $sessionId = null): StopResult
    {
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

        $this->refreshEntity($entity);

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

        $signalSent = 'term';
        $this->lifecycle->sendTerm($pid, $pgid);

        if ($graceSeconds > 0) {
            sleep($graceSeconds);
        }

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

        $now = Clock::get()->now()->format('c');
        $entity->markStoppedByUser($now);

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
     * @return int Number of cleaned records
     */
    public function cleanupStale(): int
    {
        $this->refreshAllUnfinished();

        $cutoff = Clock::get()->now()->modify('-'.$this->config->retentionSeconds.' seconds')->format('c');

        $staleEntities = $this->store->fetchStale($cutoff);

        $count = 0;
        foreach ($staleEntities as $entity) {
            $logPath = $entity->logPath;
            $statusPath = $entity->statusPath;

            $this->lifecycle->deleteRecordFiles($logPath, $statusPath);

            if ($this->store->deleteById($entity->id)) {
                ++$count;
            }
        }

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
     * Called automatically via register_shutdown_function().
     * Safe to call multiple times.
     *
     * @return int Number of processes terminated
     */
    public function shutdownCleanup(?string $sessionId = null): int
    {
        $entities = $this->store->fetchAllUnfinished($sessionId);

        $count = 0;
        foreach ($entities as $entity) {
            $pid = $entity->pid;
            try {
                $this->stop($pid);
                ++$count;
            } catch (\RuntimeException $e) {
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
     * Resolve status for an entity by checking filesystem state.
     */
    private function resolveEntityStatus(BackgroundProcess $entity): BackgroundProcessStatusEnum
    {
        if (null !== $entity->finishedAt) {
            return $entity->stoppedByUser
                ? BackgroundProcessStatusEnum::Stopped
                : BackgroundProcessStatusEnum::Finished;
        }

        $pid = $entity->pid;

        $exitCode = $this->lifecycle->readStatusFile($entity->statusPath);
        if (null !== $exitCode) {
            $now = Clock::get()->now()->format('c');
            $entity->finish($exitCode, $now);

            // Return a plain "finished" classification; the caller decides
            // whether to attach a non-zero exit code annotation.
            return BackgroundProcessStatusEnum::Finished;
        }

        if ($this->lifecycle->isAlive($pid)) {
            return BackgroundProcessStatusEnum::Running;
        }

        $now = Clock::get()->now()->format('c');
        $entity->finish(null, $now);

        $this->logger->info('background_process.finished_unclean', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.finished_unclean',
            'process_pid' => $pid,
        ]);

        return BackgroundProcessStatusEnum::FinishedUnclean;
    }

    private function refreshEntity(BackgroundProcess $entity): void
    {
        $this->resolveEntityStatus($entity);
        $this->store->flush();
    }

    private function refreshAllUnfinished(): void
    {
        $entities = $this->store->fetchAllUnfinished();

        foreach ($entities as $entity) {
            $this->resolveEntityStatus($entity);
        }

        $this->store->flush();
    }
}
