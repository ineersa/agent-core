<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

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
 * Restart policy:
 * - Up to 3 restarts within a 60-second window per transport.
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
 * - getProcess(): exposes the Process object so HeadlessController can
 *   read the LLM consumer's stdout for transient streaming deltas
 */
final class ConsumerSupervisor
{
    private const int MAX_RESTARTS = 3;
    private const int RESTART_WINDOW_SECONDS = 60;
    private const int INITIAL_RESTART_DELAY_MS = 1000;
    /** @var array<string, Process> transportName => process */
    private array $consumers = [];

    /** @var array<string, int> transportName => restart count */
    private array $restartCounts = [];

    /** @var array<string, float> transportName => start of restart window (microtime) */
    private array $restartWindows = [];

    /** Set by shutdown() to prevent pending delay callbacks from launching new consumers. */
    private bool $shuttingDown = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $shutdownGraceSeconds = 5,
    ) {
    }

    /**
     * Launch a messenger:consume child process for the given transport.
     */
    public function launch(string $transportName): void
    {
        $entrypoint = (string) ($_SERVER['argv'][0] ?? '');
        $cwd = getcwd();

        if ('' === $entrypoint || false === $cwd) {
            $this->logger->error('Cannot launch messenger consumer: invalid entrypoint or CWD', [
                'transport' => $transportName,
                'entrypoint' => $entrypoint,
                'cwd' => false === $cwd ? null : $cwd,
            ]);

            return;
        }

        try {
            $process = new Process(
                [
                    \PHP_BINARY,
                    $entrypoint,
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
            $this->logger->error('Failed to launch messenger consumer', [
                'transport' => $transportName,
                'entrypoint' => $entrypoint,
                'cwd' => $cwd,
                'exception' => $e,
            ]);

            return;
        }

        $this->consumers[$transportName] = $process;

        $this->logger->info('Launched messenger consumer', [
            'transport' => $transportName,
            'pid' => $process->getPid(),
        ]);
    }

    /**
     * Get the Symfony Process for a transport, if launched.
     *
     * The controller uses this to read the LLM consumer's stdout pipe
     * for transient streaming deltas (thinking, text, tool-call args).
     */
    public function getProcess(string $transportName): ?Process
    {
        return $this->consumers[$transportName] ?? null;
    }

    /**
     * Check consumer child process health.
     */
    public function supervise(): void
    {
        foreach ($this->consumers as $transportName => $process) {
            if ($process->isRunning()) {
                continue;
            }

            $exitCode = $process->getExitCode();
            $stderr = $process->getErrorOutput();

            $this->logger->warning('Consumer process exited unexpectedly', [
                'transport' => $transportName,
                'pid' => $process->getPid(),
                'exit_code' => $exitCode,
                'stderr' => '' !== $stderr ? $stderr : null,
            ]);

            unset($this->consumers[$transportName]);

            $this->attemptRestart($transportName);
        }
    }

    /**
     * Gracefully stop all tracked consumer processes.
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

        $this->logger->info('Stopping consumer processes', [
            'count' => \count($this->consumers),
        ]);

        foreach ($this->consumers as $transportName => $process) {
            $pid = $process->getPid();

            // stop($timeout) sends SIGTERM, waits up to timeout seconds,
            // then SIGKILL if still running. Default second signal is SIGKILL.
            $process->stop($this->shutdownGraceSeconds);

            if ($process->isRunning()) {
                $this->logger->warning('Consumer did not stop gracefully, sending SIGKILL', [
                    'transport' => $transportName,
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
    private function attemptRestart(string $transportName): void
    {
        $now = microtime(true);

        // Check if restart window has expired — reset counter.
        if (isset($this->restartWindows[$transportName])) {
            $elapsed = $now - $this->restartWindows[$transportName];
            if ($elapsed > self::RESTART_WINDOW_SECONDS) {
                $this->restartCounts[$transportName] = 0;
                unset($this->restartWindows[$transportName]);
            }
        }

        $count = $this->restartCounts[$transportName] ?? 0;

        if ($count >= self::MAX_RESTARTS) {
            $this->logger->critical('Consumer restart limit reached, not restarting', [
                'transport' => $transportName,
                'max_restarts' => self::MAX_RESTARTS,
                'window_seconds' => self::RESTART_WINDOW_SECONDS,
            ]);

            return;
        }

        // Start restart window on first restart.
        if (!isset($this->restartWindows[$transportName])) {
            $this->restartWindows[$transportName] = $now;
        }

        $this->restartCounts[$transportName] = $count + 1;

        // Exponential backoff: 1s, 2s, 4s
        $delayMs = self::INITIAL_RESTART_DELAY_MS * (2 ** $count);

        $this->logger->info('Restarting consumer with backoff', [
            'transport' => $transportName,
            'restart_attempt' => $count + 1,
            'max_restarts' => self::MAX_RESTARTS,
            'delay_ms' => $delayMs,
        ]);

        // Non-blocking delay: schedule the launch after backoff without
        // blocking the event loop.
        EventLoop::delay($delayMs / 1000, function () use ($transportName): void {
            if ($this->shuttingDown) {
                return;
            }

            // Re-check restart window hasn't expired while waiting.
            if (isset($this->restartWindows[$transportName])) {
                $this->launch($transportName);
            }
        });
    }
}
