<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

use Symfony\Component\Process\Process;

/**
 * Supervises the lifecycle of a headless agent process.
 *
 * Handles spawning, health-check heartbeats, restart-on-crash, and graceful shutdown.
 *
 * @todo Implement restart policy, heartbeat monitoring, stderr log capture,
 *       and reconnection logic.
 */
final class AgentProcessSupervisor
{
    private ?Process $process = null;

    private int $restartCount = 0;

    private int $maxRestarts = 3;

    public function __construct(
        private readonly string $consolePath,
        private readonly int $heartbeatInterval = 5,
    ) {
    }

    public function isRunning(): bool
    {
        return null !== $this->process && $this->process->isRunning();
    }

    public function start(): void
    {
        if ($this->isRunning()) {
            return;
        }

        $this->process = new Process(
            command: ['php', $this->consolePath, 'agent', '--headless'],
        );

        $this->process->setTimeout(null);
        $this->process->start();
    }

    public function stop(int $timeout = 3): void
    {
        if (null === $this->process) {
            return;
        }

        if ($this->process->isRunning()) {
            $this->process->stop($timeout);
        }

        $this->process = null;
    }

    public function restart(): void
    {
        $this->stop();
        $this->start();
        ++$this->restartCount;
    }

    /**
     * @return int stderr lines from the process
     */
    public function getErrorOutput(): string
    {
        return null !== $this->process ? $this->process->getErrorOutput() : '';
    }
}
