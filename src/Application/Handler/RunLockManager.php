<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Symfony\Component\Lock\LockFactory;

/**
 * Provides per-run distributed locking to guard critical sections against concurrent execution.
 */
final readonly class RunLockManager
{
    public function __construct(
        private LockFactory $lockFactory,
        private float $ttlSeconds = 30.0,
        private float $acquireTimeoutSeconds = 5.0,
    ) {
    }

    /**
     * Acquires a lock for the run ID and executes the critical section.
     *
     * @template T
     *
     * @param callable():T $criticalSection
     *
     * @return T
     */
    public function synchronized(string $runId, callable $criticalSection): mixed
    {
        $lock = $this->lockFactory->createLock($this->lockKey($runId), $this->ttlSeconds, autoRelease: false);

        $acquireUntil = microtime(true) + max(0.001, $this->acquireTimeoutSeconds);

        while (!$lock->acquire()) {
            if (microtime(true) >= $acquireUntil) {
                throw new \RuntimeException(\sprintf('Failed to acquire run lock for "%s" within %.3f seconds.', $runId, $this->acquireTimeoutSeconds));
            }

            usleep(20_000);
        }

        try {
            return $criticalSection();
        } finally {
            if ($lock->isAcquired()) {
                $lock->release();
            }
        }
    }

    private function lockKey(string $runId): string
    {
        return \sprintf('agent_loop.run.%s', $runId);
    }
}
