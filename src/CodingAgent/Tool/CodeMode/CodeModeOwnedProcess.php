<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;

/** Native process observation and stop handle, not a worker-owned child. */
final readonly class CodeModeOwnedProcess
{
    public function __construct(private ProcessLifecycle $lifecycle, private string $statusPath, private int $pid)
    {
    }

    public function isRunning(): bool
    {
        return null === $this->getExitCode() && $this->lifecycle->isAlive($this->pid);
    }

    public function getExitCode(): ?int
    {
        return $this->lifecycle->readStatusFile($this->statusPath);
    }

    public function stop(): void
    {
        $this->lifecycle->withSidecarLock($this->statusPath, $this->stopLocked(...));
    }

    public function stopLocked(): void
    {
        $this->lifecycle->stopProcess($this->pid, $this->pid, $this->statusPath);
    }
}
