<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\SourceTreeExecutableLocator;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Process\Process;

/**
 * Manages messenger:consume child processes for the controller.
 *
 * Launches Symfony Process-based consumers for a given transport,
 * supervises their health via isRunning(), restarts crashed consumers
 * with exponential backoff, and gracefully stops them on shutdown.
 *
 * Supports multiple consumer instances per transport (e.g. multiple
 * "tool" workers for parallel tool execution). Each instance is tracked
 * by a composite key: transportName#instanceId.
 *
 * Restart policy:
 * - Up to 3 restarts within a 60-second window per consumer key.
 * - After exhausting retries, the consumer is not restarted and
 *   a critical warning is logged.
 * - The restart window is sliding: if 60 seconds pass without a
 *   restart, the counter resets.
 *
 * Process management:
 * - Launch: creates a non-blocking Symfony Process with timeout(null)
 * - Supervision: polls isRunning() every 5s, restarts if crashed
 * - Shutdown: sends SIGTERM with configurable grace period, then SIGKILL
 * - stderr output is captured and logged on crash for diagnostics
 * - getProcess(): exposes the first Process for a transport name so
 *   HeadlessController can read the LLM consumer's stdout
 *
 * App executable resolution:
 * - Uses AppExecutableLocator to resolve the agent binary command
 *   independently of the runtime working directory (--cwd). This ensures
 *   messenger consumers always use the correct binary even when the
 *   runtime CWD is an isolated Hatfield project or a test temp directory.
 * - The consumer process CWD is set to getcwd() (runtime CWD) for
 *   correct Hatfield project behavior (settings, sessions, logs).
 */
final class ConsumerSupervisor
{
    private const int MAX_RESTARTS = 3;
    private const int RESTART_WINDOW_SECONDS = 60;
    private const int INITIAL_RESTART_DELAY_MS = 1000;

    /** @var array<string, Process> compositeKey => process */
    private array $consumers = [];

    /** @var array<string, int> compositeKey => restart count */
    private array $restartCounts = [];

    /** @var array<string, float> compositeKey => start of restart window (microtime) */
    private array $restartWindows = [];

    /** Set by shutdown() to prevent pending delay callbacks from launching new consumers. */
    private bool $shuttingDown = false;

    private readonly AppExecutableLocator $executableLocator;

    public function __construct(
        private readonly LoggerInterface $logger,
        ?AppExecutableLocator $executableLocator = null,
        private readonly int $shutdownGraceSeconds = 5,
    ) {
        $this->executableLocator = $executableLocator ?? new SourceTreeExecutableLocator(
            \dirname(__DIR__, 4),
        );
    }

    /**
     * Launch a messenger:consume child process for the given transport.
     *
     * Multiple instances of the same transport can be launched with
     * different $instanceId values (e.g. 0, 1, 2 for tool workers).
     */
    public function launch(string $transportName, int $instanceId = 0): void
    {
        $cwd = getcwd();

        if (false === $cwd) {
            $this->logger->error('Cannot launch messenger consumer: no current working directory', [
                'transport' => $transportName,
                'instance' => $instanceId,
            ]);

            return;
        }

        $appCommand = $this->executableLocator->command();

        try {
            $process = new Process(
                [
                    ...$appCommand,
                    'messenger:consume',
                    $transportName,
                    '--no-interaction',
                    '--time-limit=3600',
                ],
                cwd: $cwd,
                timeout: null,
            );

            $process->start();
        } catch (\Throwable $e) {
            // Consumer launch failure is terminal — the controller
            // cannot process any work without its consumers. Throw
            // so the process fails loudly. This prevents the
            // "controller ready but nothing works" hang.
            throw new \RuntimeException(\sprintf('Failed to launch messenger consumer for transport "%s" instance %d: %s', $transportName, $instanceId, $e->getMessage()), previous: $e);
        }

        $key = $this->consumerKey($transportName, $instanceId);
        $this->consumers[$key] = $process;

        $this->logger->info('Launched messenger consumer', [
            'transport' => $transportName,
            'instance' => $instanceId,
            'key' => $key,
            'pid' => $process->getPid(),
        ]);
    }

    /**
     * Launch multiple consumer instances for the same transport.
     *
     * Used to scale tool workers for parallel execution.
     */
    public function launchMultiple(string $transportName, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->launch($transportName, $i);
        }
    }

    /**
     * Get the Symfony Process for a transport, returning the first instance.
     *
     * The controller uses this to read the LLM consumer's stdout pipe
     * for transient streaming deltas (thinking, text, tool-call args).
     * Returns null when there are no instances or when there are multiple
     * instances (caller should use getProcesses() instead).
     */
    public function getProcess(string $transportName): ?Process
    {
        // Prefer the single-instance key first.
        $singleKey = $this->consumerKey($transportName, 0);

        if (isset($this->consumers[$singleKey])) {
            return $this->consumers[$singleKey];
        }

        // Fallback: find first matching any instance.
        foreach ($this->consumers as $key => $process) {
            if ($this->extractTransportName($key) === $transportName) {
                return $process;
            }
        }

        return null;
    }

    /**
     * Get all running consumer processes for a transport.
     *
     * @return list<Process>
     */
    public function getProcesses(string $transportName): array
    {
        $processes = [];

        foreach ($this->consumers as $key => $process) {
            if ($this->extractTransportName($key) === $transportName) {
                $processes[] = $process;
            }
        }

        return $processes;
    }

    /**
     * Check consumer child process health.
     */
    public function supervise(): void
    {
        foreach ($this->consumers as $key => $process) {
            if ($process->isRunning()) {
                continue;
            }

            $exitCode = $process->getExitCode();
            $stderr = $process->getErrorOutput();

            $this->logger->warning('Consumer process exited unexpectedly', [
                'key' => $key,
                'transport' => $this->extractTransportName($key),
                'pid' => $process->getPid(),
                'exit_code' => $exitCode,
                'stderr' => '' !== $stderr ? $stderr : null,
            ]);

            unset($this->consumers[$key]);

            $this->attemptRestart($key);
        }
    }

    /**
     * Gracefully stop all tracked messenger consumer processes.
     *
     * This is for controller/runtime shutdown only (e.g. when the user
     * exits the agent or the controller process is stopping). It is NOT
     * part of run cancellation — individual tool workers self-terminate
     * via their own cancellation token polling.
     *
     * Sends SIGTERM, waits up to shutdownGraceSeconds for graceful exit,
     * then escalates to SIGKILL if still running.
     */
    public function shutdown(): void
    {
        $this->shuttingDown = true;

        if ([] === $this->consumers) {
            return;
        }

        $this->logger->info('Shutting down messenger consumers (controller stopping)', [
            'count' => \count($this->consumers),
        ]);

        foreach ($this->consumers as $key => $process) {
            $pid = $process->getPid();
            $process->stop($this->shutdownGraceSeconds);

            if ($process->isRunning()) {
                $this->logger->warning('Messenger consumer still running after grace period, may have been killed', [
                    'key' => $key,
                    'pid' => $pid,
                ]);
            }
        }

        $this->consumers = [];
    }

    /**
     * Try to restart a crashed consumer, respecting the restart policy.
     *
     * Uses Revolt EventLoop::delay() instead of usleep() so the event loop
     * remains responsive during backoff (stdin commands, LLM stdout polling,
     * event drain, and signal handling continue to work).
     */
    private function attemptRestart(string $key): void
    {
        $transportName = $this->extractTransportName($key);
        $instanceId = $this->extractInstanceId($key);

        $now = microtime(true);

        // Check if restart window has expired — reset counter.
        if (isset($this->restartWindows[$key])) {
            $elapsed = $now - $this->restartWindows[$key];
            if ($elapsed > self::RESTART_WINDOW_SECONDS) {
                $this->restartCounts[$key] = 0;
                unset($this->restartWindows[$key]);
            }
        }

        $count = $this->restartCounts[$key] ?? 0;

        if ($count >= self::MAX_RESTARTS) {
            $this->logger->critical('Consumer restart limit reached, not restarting', [
                'key' => $key,
                'transport' => $transportName,
                'max_restarts' => self::MAX_RESTARTS,
                'window_seconds' => self::RESTART_WINDOW_SECONDS,
            ]);

            return;
        }

        // Start restart window on first restart.
        if (!isset($this->restartWindows[$key])) {
            $this->restartWindows[$key] = $now;
        }

        $this->restartCounts[$key] = $count + 1;

        // Exponential backoff: 1s, 2s, 4s
        $delayMs = self::INITIAL_RESTART_DELAY_MS * (2 ** $count);

        $this->logger->info('Restarting consumer with backoff', [
            'key' => $key,
            'transport' => $transportName,
            'instance' => $instanceId,
            'restart_attempt' => $count + 1,
            'max_restarts' => self::MAX_RESTARTS,
            'delay_ms' => $delayMs,
        ]);

        // Non-blocking delay: schedule the launch after backoff without
        // blocking the event loop.
        EventLoop::delay($delayMs / 1000, function () use ($transportName, $instanceId): void {
            if ($this->shuttingDown) {
                return;
            }

            // Re-check restart window hasn't expired while waiting.
            if (isset($this->restartWindows[$this->consumerKey($transportName, $instanceId)])) {
                $this->launch($transportName, $instanceId);
            }
        });
    }

    /**
     * Build a composite key for a consumer instance.
     */
    private function consumerKey(string $transportName, int $instanceId): string
    {
        if (str_contains($transportName, '#')) {
            throw new \InvalidArgumentException('Messenger transport names used by ConsumerSupervisor may not contain "#".');
        }

        return \sprintf('%s#%d', $transportName, $instanceId);
    }

    /**
     * Extract the transport name from a composite key.
     */
    private function extractTransportName(string $key): string
    {
        $separatorPos = strrpos($key, '#');

        if (false === $separatorPos) {
            return $key;
        }

        return substr($key, 0, $separatorPos);
    }

    /**
     * Extract the instance ID from a composite key.
     */
    private function extractInstanceId(string $key): int
    {
        $separatorPos = strrpos($key, '#');

        if (false === $separatorPos) {
            return 0;
        }

        return (int) substr($key, $separatorPos + 1);
    }
}
