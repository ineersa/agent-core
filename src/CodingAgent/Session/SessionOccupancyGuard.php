<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\CodingAgent\Config\AppConfig;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Holds a per-process Symfony flock lock for the active TUI session.
 *
 * OS flock release on process death (crash, SIGKILL) satisfies stale-lock
 * cleanup without PID files. The cwd+sessionId key prevents cross-worktree
 * collisions when parallel test workers mint the same session id in different
 * project directories.
 */
final class SessionOccupancyGuard
{
    private ?LockInterface $held = null;

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly AppConfig $appConfig,
    ) {
    }

    public function tryAcquire(string $sessionId): bool
    {
        if (null !== $this->held) {
            $this->held->release();
            $this->held = null;
        }

        $lock = $this->lockFactory->createLock($this->lockKey($sessionId));
        if (!$lock->acquire(false)) {
            return false;
        }

        $this->held = $lock;

        return true;
    }

    public function release(): void
    {
        if (null === $this->held) {
            return;
        }

        $this->held->release();
        $this->held = null;
    }

    private function lockKey(string $sessionId): string
    {
        return 'hatfield-tui-occupancy-'.$this->appConfig->cwd.':'.$sessionId;
    }
}
