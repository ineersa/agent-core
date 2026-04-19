<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Symfony\Component\Lock\LockFactory;

/**
 * The RunLockManager provides a mechanism to execute critical sections of code exclusively for a specific run ID using distributed locking. It leverages a LockFactory to acquire locks with a configurable time-to-live, ensuring thread-safe or process-safe execution of run-specific logic.
 */
final readonly class RunLockManager
{
    /**
     * Initializes the manager with a lock factory and default TTL.
     */
    public function __construct(
        private LockFactory $lockFactory,
        private float $ttlSeconds = 30.0,
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

        if (!$lock->acquire(blocking: true)) {
            throw new \RuntimeException(\sprintf('Failed to acquire run lock for "%s".', $runId));
        }

        try {
            return $criticalSection();
        } finally {
            if ($lock->isAcquired()) {
                $lock->release();
            }
        }
    }

    /**
     * Generates a unique string key for the given run ID.
     */
    private function lockKey(string $runId): string
    {
        return \sprintf('agent_loop.run.%s', $runId);
    }
}
