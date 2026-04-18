<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Symfony\Component\Lock\LockFactory;

final readonly class RunLockManager
{
    public function __construct(
        private LockFactory $lockFactory,
        private float $ttlSeconds = 30.0,
    ) {
    }

    /**
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

    private function lockKey(string $runId): string
    {
        return \sprintf('agent_loop.run.%s', $runId);
    }
}
