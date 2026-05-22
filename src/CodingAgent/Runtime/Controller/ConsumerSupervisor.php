<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Manages messenger:consume child processes for the controller.
 *
 * Launches Symfony Process-based consumers for a given transport,
 * supervises their health via isRunning(), and gracefully stops them
 * on shutdown.
 *
 * Process management:
 * - Launch: creates a non-blocking Symfony Process with timeout(null)
 * - Supervision: polls isRunning() every 5s, logs crashed consumers
 * - Shutdown: sends SIGTERM with 5s grace period, then SIGKILL
 *
 * Currently used for consumer process tracking; actual launching of
 * llm/tool/run_control consumers is deferred to ASYNC-04+.
 */
final class ConsumerSupervisor
{
    /** @var list<Process> */
    private array $consumers = [];

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

        $this->consumers[] = $process;

        $this->logger->info('Launched messenger consumer', [
            'transport' => $transportName,
            'pid' => $process->getPid(),
        ]);
    }

    /**
     * Check consumer child process health.
     *
     * Removes crashed/exited processes from the tracked list and logs
     * warnings for diagnostics. Does NOT auto-restart by default —
     * the controller's run_control consumer is single-instance and
     * should be restarted manually or via a supervisor layer.
     */
    public function supervise(): void
    {
        if ([] === $this->consumers) {
            return;
        }

        $alive = [];
        foreach ($this->consumers as $process) {
            if ($process->isRunning()) {
                $alive[] = $process;
            } else {
                $this->logger->warning('Consumer process exited unexpectedly', [
                    'pid' => $process->getPid(),
                    'exit_code' => $process->getExitCode(),
                ]);
            }
        }

        $this->consumers = $alive;
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

        foreach ($this->consumers as $process) {
            $pid = $process->getPid();
            $process->stop(5, \SIGTERM);

            if ($process->isRunning()) {
                $this->logger->warning('Consumer did not stop gracefully, sending SIGKILL', [
                    'pid' => $pid,
                ]);
            }
        }

        $this->consumers = [];
    }
}
