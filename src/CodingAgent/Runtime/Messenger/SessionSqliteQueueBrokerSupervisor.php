<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\SqliteQueue\Broker\BrokerEventEnum;
use Ineersa\SqliteQueue\Sqlite\SqliteSynchronousMode;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Starts and stops the session-owned sqlite-queue broker for one controller.
 *
 * The broker owns an async SQLite driver child that does not publish its PID.
 * The broker process itself calls posix_setsid() before Amp workers start, so
 * isolation does not require an external `setsid` binary. The supervisor keeps
 * a Symfony InputStream open on the broker stdin; controller hard death closes
 * that pipe and the broker self-TERMs so durable DB lifetime locks release.
 * Shutdown TERMs the broker first so it can drain, then escalates the owned
 * group only after the remaining shared grace budget. Callers that also stop
 * consumers must pass one shared deadline via shutdownUntil() so sequential
 * consumer+broker grace cannot exceed the parent stop budget. Symfony's
 * `create_process_group` option is Windows-only and must not be treated as
 * Linux isolation.
 *
 * Endpoint cleanup is ownership-gated: only sockets created by a successful
 * start of this supervisor may be removed. Refused pre-existing endpoints are
 * never unlinked. Package lock sidecars are left in place.
 *
 * Startup waits for a positive JSON ready event. Process creation alone is not
 * readiness. Broker death after readiness is fail-visible: supervise() reports
 * it and callers must stop the session instead of reconnecting or replaying.
 */
final class SessionSqliteQueueBrokerSupervisor
{
    private const float DEFAULT_READY_TIMEOUT_SECONDS = 10.0;
    private const float DEFAULT_SHUTDOWN_GRACE_SECONDS = 5.0;
    private const int STDERR_TAIL_MAX_BYTES = 16_384;

    private ?Process $process = null;
    private ?int $processGroupId = null;
    private ?SessionSqliteQueuePaths $paths = null;
    private ?InputStream $ownerInput = null;
    private string $stdoutCarry = '';
    private string $stderrTail = '';
    private bool $ready = false;
    private bool $shuttingDown = false;
    /** True only after this supervisor successfully launched a broker for $paths. */
    private bool $ownsEndpointArtifacts = false;
    private ?int $advertisedMaxPayload = null;
    private ?int $advertisedMaxConnections = null;
    private ?int $requiredMaxConnectionsForReady = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RuntimeProcessConfig $runtimeConfig,
        private readonly Filesystem $filesystem = new Filesystem(),
        private readonly float $readyTimeoutSeconds = self::DEFAULT_READY_TIMEOUT_SECONDS,
        private readonly float $shutdownGraceSeconds = self::DEFAULT_SHUTDOWN_GRACE_SECONDS,
        private readonly ?int $redeliverTimeoutSeconds = null,
        private readonly ?SqliteSynchronousMode $synchronous = null,
        private readonly ?int $maxPayload = null,
        private readonly ?int $maxConnections = null,
    ) {
        if ($this->readyTimeoutSeconds <= 0 || !is_finite($this->readyTimeoutSeconds)) {
            throw new \InvalidArgumentException('Broker ready timeout must be positive finite seconds.');
        }
        if ($this->shutdownGraceSeconds < 0 || !is_finite($this->shutdownGraceSeconds)) {
            throw new \InvalidArgumentException('Broker shutdown grace must be non-negative finite seconds.');
        }
        if (null !== $this->redeliverTimeoutSeconds && $this->redeliverTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Broker redeliver timeout must be positive seconds.');
        }
        if (null !== $this->maxPayload && $this->maxPayload < 1) {
            throw new \InvalidArgumentException('Broker max payload must be a positive byte count.');
        }
        if (null !== $this->maxConnections && $this->maxConnections < 1) {
            throw new \InvalidArgumentException('Broker max connections must be a positive integer.');
        }
    }

    /**
     * @param int|null $requiredMaxConnections when set, launch with that budget and fail closed if ready advertises less
     */
    public function start(SessionSqliteQueuePaths $paths, ?int $requiredMaxConnections = null): void
    {
        if (null !== $this->process) {
            throw new \LogicException('Session sqlite-queue broker is already started.');
        }
        if (null !== $requiredMaxConnections && $requiredMaxConnections < 1) {
            throw new \InvalidArgumentException('Required broker max connections must be a positive integer.');
        }

        $this->shuttingDown = false;
        $this->ready = false;
        $this->ownsEndpointArtifacts = false;
        $this->stdoutCarry = '';
        $this->stderrTail = '';
        $this->paths = $paths;
        $this->advertisedMaxPayload = null;
        $this->advertisedMaxConnections = null;
        $this->requiredMaxConnectionsForReady = $requiredMaxConnections ?? $this->maxConnections;
        $this->closeOwnerInput();

        $this->preparePrivatePaths($paths);
        $this->refuseExistingEndpoint($paths->endpointPath);

        $launchCommand = [
            ...$this->runtimeConfig->executableCommand(),
            'sqlite-queue:broker',
            '--database='.$paths->databasePath,
            '--endpoint='.$paths->endpointPath,
            '--no-ansi',
            '--no-interaction',
        ];
        if (null !== $this->redeliverTimeoutSeconds) {
            $launchCommand[] = '--redeliver-timeout='.$this->redeliverTimeoutSeconds;
        }
        if (null !== $this->synchronous) {
            $launchCommand[] = '--synchronous='.$this->synchronous->value;
        }
        if (null !== $this->maxPayload) {
            $launchCommand[] = '--max-payload='.$this->maxPayload;
        }
        $effectiveMaxConnections = $this->requiredMaxConnectionsForReady;
        if (null !== $effectiveMaxConnections) {
            $launchCommand[] = '--max-connections='.$effectiveMaxConnections;
        }

        // PHP's default variables_order=GPCS leaves $_ENV empty. getenv() is the
        // actual process environment the controller inherited (HOME, HATFIELD_*,
        // PATH). Passing $_ENV alone boots the broker without HOME and exits 255
        // before ready inside fused/native images.
        $currentEnv = getenv();
        $ownerInput = new InputStream();
        $process = new Process(
            $launchCommand,
            cwd: $this->runtimeConfig->runtimeCwd(),
            // Republish session queue ownership so the broker process can distinguish
            // controller-supervised launches from standalone sqlite-queue:broker.
            env: array_merge(\is_array($currentEnv) ? $currentEnv : $_ENV, SessionSqliteQueueEnv::processEnv($paths)),
            input: $ownerInput,
            timeout: null,
        );

        try {
            $process->start();
        } catch (\Throwable $exception) {
            $ownerInput->close();
            throw new \RuntimeException(\sprintf('Failed to launch session sqlite-queue broker for session "%s": %s', $paths->sessionId, $exception->getMessage()), previous: $exception);
        }

        // Process creation succeeded: this supervisor owns cleanup for this endpoint.
        $this->ownsEndpointArtifacts = true;
        $this->process = $process;
        $this->ownerInput = $ownerInput;
        $pid = $process->getPid();
        if (null === $pid || $pid <= 0) {
            $this->forceStopOwnedTree();
            throw new \RuntimeException(\sprintf('Session sqlite-queue broker for session "%s" started without a PID.', $paths->sessionId));
        }

        $this->awaitOwnedProcessGroupIsolation($paths->sessionId, $pid);
        $this->waitForReady($paths);

        $this->logger->info('Session sqlite-queue broker ready', [
            'component' => 'SessionSqliteQueueBrokerSupervisor',
            'event_type' => 'sqlite_queue.broker.ready',
            'session_id' => $paths->sessionId,
            'pid' => $pid,
            'pgid' => $this->processGroupId,
            'database' => $paths->databasePath,
            'endpoint' => $paths->endpointPath,
            'synchronous_override' => $this->synchronous?->value,
            'redeliver_timeout_override_seconds' => $this->redeliverTimeoutSeconds,
            'max_payload' => $this->advertisedMaxPayload,
            'max_connections' => $this->advertisedMaxConnections,
        ]);
    }

    public function paths(): ?SessionSqliteQueuePaths
    {
        return $this->paths;
    }

    public function isReady(): bool
    {
        return $this->ready && null !== $this->process && $this->process->isRunning();
    }

    public function advertisedMaxPayload(): ?int
    {
        return $this->advertisedMaxPayload;
    }

    public function advertisedMaxConnections(): ?int
    {
        return $this->advertisedMaxConnections;
    }

    /**
     * Returns false when the ready broker has exited. Callers must fail the session.
     */
    public function supervise(): bool
    {
        if ($this->shuttingDown || null === $this->process || !$this->ready) {
            return true;
        }

        $this->drainStreams();
        if ($this->process->isRunning()) {
            return true;
        }

        $exitCode = $this->process->getExitCode();
        $this->logger->critical('Session sqlite-queue broker exited after readiness', [
            'component' => 'SessionSqliteQueueBrokerSupervisor',
            'event_type' => 'sqlite_queue.broker.dead',
            'session_id' => $this->paths?->sessionId,
            'pid' => $this->process->getPid(),
            'pgid' => $this->processGroupId,
            'exit_code' => $exitCode,
            'stderr' => '' !== $this->stderrTail ? $this->stderrTail : null,
            'stdout' => '' !== $this->stdoutCarry ? $this->stdoutCarry : null,
        ]);

        return false;
    }

    public function shutdown(): void
    {
        $this->shutdownUntil(microtime(true) + $this->shutdownGraceSeconds);
    }

    /**
     * Stop the owned broker tree against an absolute shared deadline.
     *
     * Controllers that also stop consumers must pass the remaining shared
     * deadline so consumer+broker cleanup cannot exceed the parent budget.
     */
    public function shutdownUntil(float $deadline): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->shuttingDown = true;
        if (null === $this->process) {
            $this->closeOwnerInput();
            $this->removeOwnedEndpointArtifacts();

            return;
        }

        $sessionId = $this->paths?->sessionId;
        $remaining = max(0.0, $deadline - microtime(true));
        $this->logger->info('Shutting down session sqlite-queue broker', [
            'component' => 'SessionSqliteQueueBrokerSupervisor',
            'event_type' => 'sqlite_queue.broker.shutdown_begin',
            'session_id' => $sessionId,
            'pid' => $this->process->getPid(),
            'pgid' => $this->processGroupId,
            'grace_seconds' => $remaining,
        ]);

        // TERM the broker process first so it can drain and close its driver.
        $this->signalBroker(\SIGTERM);
        while (microtime(true) < $deadline) {
            $this->drainStreams();
            if (!$this->process->isRunning() && !$this->ownedTreeAlive()) {
                break;
            }
            usleep(50_000);
        }

        if ($this->process->isRunning() || $this->ownedTreeAlive()) {
            $this->logger->warning('Session sqlite-queue broker escalated after shared grace', [
                'component' => 'SessionSqliteQueueBrokerSupervisor',
                'event_type' => 'sqlite_queue.broker.shutdown_escalated',
                'session_id' => $sessionId,
                'pid' => $this->process->getPid(),
                'pgid' => $this->processGroupId,
            ]);
            $this->forceStopOwnedTree();
        }

        $this->drainStreams();
        $this->process = null;
        $this->processGroupId = null;
        $this->ready = false;
        $this->closeOwnerInput();
        $this->removeOwnedEndpointArtifacts();

        $this->logger->info('Session sqlite-queue broker shutdown complete', [
            'component' => 'SessionSqliteQueueBrokerSupervisor',
            'event_type' => 'sqlite_queue.broker.shutdown_complete',
            'session_id' => $sessionId,
        ]);
    }

    private function preparePrivatePaths(SessionSqliteQueuePaths $paths): void
    {
        $databaseDir = \dirname($paths->databasePath);
        $endpointDir = $paths->endpointDirectory();

        $this->filesystem->mkdir($databaseDir, 0o700);
        $this->filesystem->mkdir($endpointDir, 0o700);
        @chmod($databaseDir, 0o700);
        @chmod($endpointDir, 0o700);
    }

    private function waitForReady(SessionSqliteQueuePaths $paths): void
    {
        if (null === $this->process) {
            throw new \LogicException('Cannot wait for broker readiness without a process.');
        }

        $process = $this->process;
        $process->setTimeout($this->readyTimeoutSeconds);

        try {
            $ready = $process->waitUntil(function (string $type, string $data) use ($process): bool {
                if (Process::ERR === $type) {
                    $this->appendStderrTail($data);
                    $process->clearErrorOutput();

                    return false;
                }

                $this->stdoutCarry .= $data;
                $this->consumeReadyEvents();
                $process->clearOutput();

                return $this->ready;
            });
        } catch (ProcessTimedOutException $exception) {
            $this->forceStopOwnedTree();
            throw new \RuntimeException(\sprintf('Session sqlite-queue broker for session "%s" did not emit ready within %.1fs. stderr=%s stdout=%s', $paths->sessionId, $this->readyTimeoutSeconds, $this->stderrTail, $this->stdoutCarry), previous: $exception);
        } catch (\RuntimeException $exception) {
            $this->forceStopOwnedTree();

            throw $exception;
        } finally {
            $process->setTimeout(null);
        }

        if ($this->ready && $ready) {
            if (!$process->isRunning()) {
                $this->forceStopOwnedTree();
                throw new \RuntimeException(\sprintf('Session sqlite-queue broker for session "%s" exited immediately after ready. stderr=%s stdout=%s', $paths->sessionId, $this->stderrTail, $this->stdoutCarry));
            }

            return;
        }

        $exitCode = $process->getExitCode();
        $this->forceStopOwnedTree();
        throw new \RuntimeException(\sprintf('Session sqlite-queue broker for session "%s" exited before ready (exit_code=%s). stderr=%s stdout=%s', $paths->sessionId, null === $exitCode ? 'null' : (string) $exitCode, $this->stderrTail, $this->stdoutCarry));
    }

    private function drainStreams(): void
    {
        if (null === $this->process) {
            return;
        }

        $stdout = $this->process->getIncrementalOutput();
        if ('' !== $stdout) {
            $this->stdoutCarry .= $stdout;
            $this->consumeReadyEvents();
            $this->process->clearOutput();
            if (\strlen($this->stdoutCarry) > self::STDERR_TAIL_MAX_BYTES) {
                $this->stdoutCarry = substr($this->stdoutCarry, -self::STDERR_TAIL_MAX_BYTES);
            }
        }

        $stderr = $this->process->getIncrementalErrorOutput();
        if ('' !== $stderr) {
            $this->appendStderrTail($stderr);
            $this->process->clearErrorOutput();
        }
    }

    private function consumeReadyEvents(): void
    {
        while (false !== ($newline = strpos($this->stdoutCarry, "\n"))) {
            $line = substr($this->stdoutCarry, 0, $newline);
            $this->stdoutCarry = substr($this->stdoutCarry, $newline + 1);
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }

            try {
                $decoded = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                $this->logger->info('Ignoring non-JSON sqlite-queue broker stdout line', [
                    'component' => 'SessionSqliteQueueBrokerSupervisor',
                    'event_type' => 'sqlite_queue.broker.stdout_non_json',
                    'session_id' => $this->paths?->sessionId,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            if (!\is_array($decoded) || !isset($decoded['event']) || !\is_string($decoded['event'])) {
                continue;
            }

            if (BrokerEventEnum::Ready->value === $decoded['event']) {
                $maxPayload = $decoded['max_payload'] ?? null;
                $maxConnections = $decoded['max_connections'] ?? null;
                if (!\is_int($maxPayload) || $maxPayload < 1) {
                    throw new \RuntimeException(\sprintf('Session sqlite-queue broker for session "%s" ready event omitted a positive max_payload.', $this->paths?->sessionId ?? 'unknown'));
                }
                if (!\is_int($maxConnections) || $maxConnections < 1) {
                    throw new \RuntimeException(\sprintf('Session sqlite-queue broker for session "%s" ready event omitted a positive max_connections.', $this->paths?->sessionId ?? 'unknown'));
                }
                if (null !== $this->maxPayload && $maxPayload < $this->maxPayload) {
                    throw new \RuntimeException(\sprintf('Session sqlite-queue broker for session "%s" advertised max_payload=%d below required %d.', $this->paths?->sessionId ?? 'unknown', $maxPayload, $this->maxPayload));
                }
                if (null !== $this->requiredMaxConnectionsForReady && $maxConnections < $this->requiredMaxConnectionsForReady) {
                    throw new \RuntimeException(\sprintf('Session sqlite-queue broker for session "%s" advertised max_connections=%d below required %d.', $this->paths?->sessionId ?? 'unknown', $maxConnections, $this->requiredMaxConnectionsForReady));
                }

                $this->advertisedMaxPayload = $maxPayload;
                $this->advertisedMaxConnections = $maxConnections;
                $this->ready = true;
            }
        }
    }

    private function appendStderrTail(string $chunk): void
    {
        $tail = $this->stderrTail.$chunk;
        if (\strlen($tail) > self::STDERR_TAIL_MAX_BYTES) {
            $start = \strlen($tail) - self::STDERR_TAIL_MAX_BYTES;
            while (isset($tail[$start]) && (\ord($tail[$start]) & 0xC0) === 0x80) {
                ++$start;
            }
            $tail = substr($tail, $start);
        }

        $this->stderrTail = $tail;
    }

    private function refuseExistingEndpoint(string $endpointPath): void
    {
        clearstatcache(true, $endpointPath);
        if (!file_exists($endpointPath) && !is_link($endpointPath)) {
            return;
        }

        // Do not mark ownership and do not unlink: a foreign or leftover path
        // must survive a refused start and any subsequent shutdown() cleanup.
        throw new \RuntimeException(\sprintf('Refusing to start session sqlite-queue broker: endpoint already exists at %s. Verify no listener owns it before removing or choosing another private endpoint.', $endpointPath));
    }

    private function signalBroker(int $signal): void
    {
        if (null === $this->process || !$this->process->isRunning()) {
            return;
        }

        try {
            $this->process->signal($signal);
        } catch (\Throwable $exception) {
            $this->logger->info('Session sqlite-queue broker already gone during signal', [
                'component' => 'SessionSqliteQueueBrokerSupervisor',
                'event_type' => 'sqlite_queue.broker.signal_race',
                'signal' => $signal,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function signalOwnedTree(int $signal): void
    {
        $pgid = $this->processGroupId;
        if ($this->canSignalOwnedProcessGroup($pgid) && \function_exists('posix_kill')) {
            @posix_kill(-$pgid, $signal);

            return;
        }

        $this->signalBroker($signal);
    }

    private function forceStopOwnedTree(): void
    {
        $this->signalOwnedTree(\SIGKILL);
        if (null !== $this->process && $this->process->isRunning()) {
            $this->process->stop(0);
        }
        $this->process = null;
        $this->processGroupId = null;
        $this->ready = false;
        $this->closeOwnerInput();
    }

    private function ownedTreeAlive(): bool
    {
        $pgid = $this->processGroupId;
        if (!$this->canSignalOwnedProcessGroup($pgid) || !\function_exists('posix_kill')) {
            return false;
        }

        return @posix_kill(-$pgid, 0);
    }

    private function resolveOwnedProcessGroupId(int $pid): int
    {
        if (!\function_exists('posix_getpgid')) {
            return $pid;
        }

        $pgid = @posix_getpgid($pid);
        if (false === $pgid || $pgid <= 1) {
            return $pid;
        }

        return $pgid;
    }

    /**
     * The broker process calls posix_setsid() after Process::start() returns.
     * Sample PGID until the broker leaves the controller group (or the process dies).
     */
    private function awaitOwnedProcessGroupIsolation(string $sessionId, int $pid): void
    {
        // Isolation happens after Symfony boots ConsoleEvents::COMMAND inside the
        // broker process. Keep this wait inside the same readiness budget.
        $deadline = microtime(true) + $this->readyTimeoutSeconds;
        $lastPgid = 0;
        do {
            if (null === $this->process || !$this->process->isRunning()) {
                $this->forceStopOwnedTree();
                throw new \RuntimeException(\sprintf('Session sqlite-queue broker for session "%s" exited before process-group isolation (pid=%d).', $sessionId, $pid));
            }

            $lastPgid = $this->resolveOwnedProcessGroupId($pid);
            $this->processGroupId = $lastPgid;
            if ($this->isOwnedProcessGroupIsolated($lastPgid)) {
                return;
            }

            usleep(1_000);
        } while (microtime(true) < $deadline);

        $this->forceStopOwnedTree();
        throw new \RuntimeException(\sprintf('Session sqlite-queue broker for session "%s" did not leave the controller process group before isolation deadline (pid=%d, pgid=%d).', $sessionId, $pid, $lastPgid));
    }

    private function isOwnedProcessGroupIsolated(?int $pgid): bool
    {
        if (null === $pgid || $pgid <= 1) {
            return false;
        }

        if (!\function_exists('posix_getpgid')) {
            return true;
        }

        $selfPid = getmypid();
        $selfPgid = @posix_getpgid(false === $selfPid ? 0 : $selfPid);
        if (false === $selfPgid) {
            return true;
        }

        return $pgid !== $selfPgid;
    }

    private function canSignalOwnedProcessGroup(?int $pgid): bool
    {
        // Never escalate a process group that still includes this controller/test process.
        return $this->isOwnedProcessGroupIsolated($pgid);
    }

    private function removeOwnedEndpointArtifacts(): void
    {
        if (!$this->ownsEndpointArtifacts) {
            return;
        }

        $paths = $this->paths;
        $this->ownsEndpointArtifacts = false;
        if (null === $paths) {
            return;
        }

        // Only remove the owned socket. Never delete package lock sidecars.
        // Prefer waiting for normal broker exit (package removes its own socket);
        // this is a best-effort cleanup after abrupt escalation.
        $endpoint = $paths->endpointPath;
        clearstatcache(true, $endpoint);
        if (file_exists($endpoint) || is_link($endpoint)) {
            @unlink($endpoint);
        }
    }

    private function closeOwnerInput(): void
    {
        if (null === $this->ownerInput) {
            return;
        }

        if (!$this->ownerInput->isClosed()) {
            $this->ownerInput->close();
        }
        $this->ownerInput = null;
    }
}
