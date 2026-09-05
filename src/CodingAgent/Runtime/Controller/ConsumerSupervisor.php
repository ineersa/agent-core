<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
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
 * - Launch: creates a non-blocking Symfony Process with timeout(null) and
 *   Symfony Messenger --memory-limit for graceful worker recycling
 * - Claimed Doctrine rows are never reclaimed by age; abandoned deliveries
 *   require explicit `/repair` redrive of current effects
 * - Supervision: polls isRunning() every 5s; exit code 0 is treated as
 *   normal memory-limit (or other graceful) recycle with immediate relaunch;
 *   non-zero exits use crash restart policy with exponential backoff
 * - Shutdown: SIGTERM all tracked consumers first, one shared grace deadline,
 *   then escalate survivors with Process::stop(0) (SIGKILL)
 * - stderr is drained incrementally during stdout reads; a bounded tail per
 *   consumer key is retained for abnormal-exit diagnostics (Symfony Process
 *   buffers are cleared so idle polling does not retain the full event bus
 *   history)
 *
 * App executable and runtime CWD resolution:
 * - Uses RuntimeProcessConfig to provide both the agent binary command
 *   (via AppExecutableLocator) and the canonical runtime working directory
 *   (from %app.cwd% / HATFIELD_CWD), so messenger consumers always use the
 *   correct binary and Hatfield project CWD regardless of the controller's
 *   own --cwd or the parent process CWD
 */
final class ConsumerSupervisor implements ConsumerStdoutSourceInterface
{
    /** Max partial stdout line retained by the poller when JSONL spans reads. */
    public const int PARTIAL_STDOUT_MAX_BYTES = 65_536;
    private const int MAX_RESTARTS = 3;
    private const int RESTART_WINDOW_SECONDS = 60;
    private const int INITIAL_RESTART_DELAY_MS = 1000;

    /** Symfony Messenger graceful worker recycle threshold for controller consumers. */
    private const string CONSUMER_MEMORY_LIMIT = '256M';

    /**
     * Idle poll delay passed to messenger:consume in seconds (10ms).
     * Symfony converts this CLI value to microseconds for Worker::run().
     */
    private const float CONSUMER_SLEEP_SECONDS = 0.01;

    /** Max bytes of stderr tail retained per consumer for crash diagnostics. */
    private const int STDERR_TAIL_MAX_BYTES = 16_384;

    /** @var array<string, Process> compositeKey => process */
    private array $consumers = [];

    /** @var array<string, int> compositeKey => restart count */
    private array $restartCounts = [];

    /** @var array<string, float> compositeKey => start of restart window (microtime) */
    private array $restartWindows = [];

    /** @var array<string, string> consumerKey => bounded stderr tail */
    private array $stderrTails = [];

    /** Set by shutdown() to prevent pending delay callbacks from launching new consumers. */
    private bool $shuttingDown = false;

    /**
     * Optional callback invoked when a consumer is abandoned after the restart
     * limit is reached. Receives the consumer key and transport name so the
     * controller can surface a diagnostic to the TUI.
     *
     * @var (callable(string, string): void)|null
     */
    private $onConsumerAbandoned;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RuntimeProcessConfig $runtimeConfig,
        private readonly int $shutdownGraceSeconds = 5,
    ) {
    }

    /**
     * Launch a messenger:consume child process for the given transport.
     *
     * Multiple instances of the same transport can be launched with
     * different $instanceId values (e.g. 0, 1, 2 for tool workers).
     */
    public function launch(string $transportName, int $instanceId = 0): void
    {
        $cwd = $this->runtimeConfig->runtimeCwd();
        $appCommand = $this->runtimeConfig->executableCommand();

        try {
            $env = $_ENV;
            $env['HATFIELD_CONSUMER_STDOUT_EVENTS'] = '1';

            $process = new Process(
                [
                    ...$appCommand,
                    'messenger:consume',
                    $transportName,
                    '--no-interaction',
                    '--memory-limit='.self::CONSUMER_MEMORY_LIMIT,
                    // Explicit values avoid Symfony's one-second default idle sleep.
                    '--sleep='.self::CONSUMER_SLEEP_SECONDS,
                ],
                cwd: $cwd,
                env: $env,
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
     * @return iterable<string, string>
     */
    public function readIncrementalStdoutByConsumer(): iterable
    {
        foreach ($this->consumers as $key => $process) {
            if (!$process->isRunning()) {
                continue;
            }

            $this->drainAndClearStderr($key, $process);

            $chunk = $process->getIncrementalOutput();
            if ('' !== $chunk) {
                yield $key => $chunk;
                // ConsumerStdoutPoller owns partial-line buffering; drop Symfony's
                // cumulative stdout so idle polling does not retain the full bus.
                $process->clearOutput();
            }
        }
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
            $this->drainAndClearStderr($key, $process);
            $stderr = $this->stderrTails[$key] ?? '';
            if ('' === $stderr) {
                $stderr = $process->getErrorOutput();
            }

            $transportName = $this->extractTransportName($key);
            $instanceId = $this->extractInstanceId($key);

            unset($this->stderrTails[$key]);
            unset($this->consumers[$key]);

            if (0 === $exitCode) {
                $this->logger->info('Consumer process exited gracefully, recycling', [
                    'component' => 'ConsumerSupervisor',
                    'event_type' => 'consumer.graceful_recycle',
                    'key' => $key,
                    'transport' => $transportName,
                    'instance' => $instanceId,
                    'pid' => $process->getPid(),
                    'exit_code' => $exitCode,
                    'stderr' => '' !== $stderr ? $stderr : null,
                ]);

                unset($this->restartCounts[$key], $this->restartWindows[$key]);

                if (!$this->shuttingDown) {
                    $this->launch($transportName, $instanceId);
                }

                continue;
            }

            $this->logger->warning('Consumer process exited unexpectedly', [
                'component' => 'ConsumerSupervisor',
                'event_type' => 'consumer.abnormal_exit',
                'key' => $key,
                'transport' => $transportName,
                'pid' => $process->getPid(),
                'exit_code' => $exitCode,
                'stderr' => '' !== $stderr ? $stderr : null,
            ]);

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
     * Two-phase shutdown under ONE shared grace deadline:
     * 1) SIGTERM every currently running tracked Process first (no wait),
     * 2) poll until all exit or shutdownGraceSeconds elapses,
     * 3) escalate only remaining tracked children with Process::stop(0).
     *
     * Sequential Process::stop(grace) per child would delay the last consumer
     * by N×grace and orphan the tail of a large pool under parent timeout.
     */
    public function shutdown(): void
    {
        $this->shuttingDown = true;

        if ([] === $this->consumers) {
            return;
        }

        $this->logger->info('Shutting down messenger consumers (controller stopping)', [
            'component' => 'ConsumerSupervisor',
            'event_type' => 'consumer.shutdown_begin',
            'count' => \count($this->consumers),
            'grace_seconds' => $this->shutdownGraceSeconds,
        ]);

        // Phase 1: signal every running tracked child before any wait.
        foreach ($this->consumers as $key => $process) {
            if (!$process->isRunning()) {
                continue;
            }

            try {
                $process->signal(\SIGTERM);
            } catch (\Throwable $e) {
                // Process may have exited between isRunning() and signal(); keep going.
                $this->logger->info('Messenger consumer already gone during SIGTERM', [
                    'component' => 'ConsumerSupervisor',
                    'event_type' => 'consumer.shutdown_signal_race',
                    'key' => $key,
                    'pid' => $process->getPid(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Phase 2: one shared grace deadline for the whole tracked set.
        $deadline = microtime(true) + $this->shutdownGraceSeconds;
        while (microtime(true) < $deadline) {
            $anyRunning = false;
            foreach ($this->consumers as $process) {
                if ($process->isRunning()) {
                    $anyRunning = true;
                    break;
                }
            }
            if (!$anyRunning) {
                break;
            }
            usleep(50_000);
        }

        // Phase 3: escalate only survivors (Process::stop(0) => immediate SIGKILL path).
        foreach ($this->consumers as $key => $process) {
            if (!$process->isRunning()) {
                continue;
            }

            $pid = $process->getPid();
            $process->stop(0);
            // stop(0) is best-effort; log that this key needed escalation after shared grace.
            $this->logger->warning('Messenger consumer escalated after shared grace via stop(0)', [
                'component' => 'ConsumerSupervisor',
                'event_type' => 'consumer.shutdown_escalated',
                'key' => $key,
                'pid' => $pid,
            ]);
        }

        $this->logger->info('Messenger consumer shutdown complete', [
            'component' => 'ConsumerSupervisor',
            'event_type' => 'consumer.shutdown_complete',
            'count' => \count($this->consumers),
        ]);

        $this->consumers = [];
    }

    /**
     * Set a callback that is invoked when a consumer is abandoned after the
     * restart limit is reached.  The callback receives the consumer key and
     * transport name so the controller can emit a diagnostic runtime event.
     *
     * @param callable(string, string): void $callback
     */
    public function onConsumerAbandoned(callable $callback): void
    {
        $this->onConsumerAbandoned = $callback;
    }

    private function drainAndClearStderr(string $key, Process $process): void
    {
        $chunk = $process->getIncrementalErrorOutput();
        if ('' !== $chunk) {
            $this->appendStderrTail($key, $chunk);
        }

        $process->clearErrorOutput();
    }

    private function appendStderrTail(string $key, string $chunk): void
    {
        $tail = ($this->stderrTails[$key] ?? '').$chunk;
        if (\strlen($tail) > self::STDERR_TAIL_MAX_BYTES) {
            $tail = substr($tail, -self::STDERR_TAIL_MAX_BYTES);
        }

        $this->stderrTails[$key] = $tail;
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

            // Notify controller so it can surface a diagnostic to the TUI
            // instead of leaving the user staring at "Working..." forever.
            if (null !== $this->onConsumerAbandoned) {
                ($this->onConsumerAbandoned)($key, $transportName);
            }

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
