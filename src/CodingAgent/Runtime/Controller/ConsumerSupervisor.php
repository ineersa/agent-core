<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Psr\Log\LoggerInterface;
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
 * - Shutdown: sends SIGTERM with 5s grace period, then SIGKILL
 * - stderr output is captured and logged on crash for diagnostics
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

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Launch a messenger:consume child process for the given transport.
     *
     * The process runs non-blocking with no time limit. Tracked for
     * supervision and graceful shutdown.
     */
    public function launch(string $transportName): void
    {
        $entrypoint = $_SERVER['argv'][0];

        $process = new Process(
            [
                \PHP_BINARY,
                $entrypoint,
                'messenger:consume',
                $transportName,
                '--no-interaction',
                '--time-limit=3600',
            ],
            timeout: null,
        );

        $process->start();

        $this->consumers[$transportName] = $process;

        $this->logger->info('Launched messenger consumer', [
            'transport' => $transportName,
            'pid' => $process->getPid(),
        ]);
    }

    /**
     * Check consumer child process health.
     *
     * Removes crashed/exited processes from the tracked list and
     * automatically restarts them if the restart policy allows.
     * Captures and logs stderr output on crash for diagnostics.
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
     * Sends SIGTERM with a 5-second grace period. Processes that do not
     * terminate within the timeout receive SIGKILL.
     */
    public function shutdown(): void
    {
        if ([] === $this->consumers) {
            return;
        }

        $this->logger->info('Stopping consumer processes', [
            'count' => \count($this->consumers),
        ]);

        foreach ($this->consumers as $transportName => $process) {
            $pid = $process->getPid();
            $process->stop(5, \SIGTERM);

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
     */
    private function attemptRestart(string $transportName): void
    {
        $now = microtime(true);

        // Check if restart window has expired — reset counter
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

        // Start restart window on first restart
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

        // Sleep before restarting (outside event loop — this runs in
        // the supervisor repeat callback, which is one-shot per tick)
        usleep($delayMs * 1000);

        $this->launch($transportName);
    }
}
