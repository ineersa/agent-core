<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class RunLockManager
{
    /** @var array<string, LockInterface> */
    private array $activeLocks = [];

    /** @var array<string, list<callable(): void>> */
    private array $afterRelease = [];

    public function __construct(
        private LockFactory $lockFactory,
        private float $ttlSeconds = 30.0,
        private float $acquireTimeoutSeconds = 5.0,
    ) {
    }

    /**
     * Acquires a lock for the run ID and executes the critical section.
     *
     * Supports re-entrant calls: if this manager already holds the
     * lock for the same runId, the critical section runs immediately
     * without a second acquire/release cycle.
     *
     * @template T
     *
     * @param callable():T $criticalSection
     *
     * @return T
     */
    public function synchronized(string $runId, callable $criticalSection): mixed
    {
        $key = $this->lockKey($runId);

        // Re-entrant guard: if we already hold this lock, run directly.
        if (isset($this->activeLocks[$key])) {
            return $criticalSection();
        }

        $lock = $this->lockFactory->createLock($key, $this->ttlSeconds, autoRelease: false);

        $acquireUntil = microtime(true) + max(0.001, $this->acquireTimeoutSeconds);

        while (!$lock->acquire()) {
            if (microtime(true) >= $acquireUntil) {
                throw new \RuntimeException(\sprintf('Failed to acquire run lock for "%s" within %.3f seconds.', $runId, $this->acquireTimeoutSeconds));
            }

            usleep(20_000);
        }

        $this->activeLocks[$key] = $lock;

        try {
            return $criticalSection();
        } finally {
            unset($this->activeLocks[$key]);

            if ($lock->isAcquired()) {
                $lock->release();
            }

            $callbacks = $this->afterRelease[$key] ?? [];
            unset($this->afterRelease[$key]);
            foreach ($callbacks as $callback) {
                $callback();
            }
        }
    }

    /**
     * Runs after this process releases the outermost lock for the run.
     * Nested synchronized sections must not observe broker side effects early.
     *
     * @param callable(): void $callback
     */
    public function afterRelease(string $runId, callable $callback): void
    {
        $key = $this->lockKey($runId);
        if (!isset($this->activeLocks[$key])) {
            $callback();

            return;
        }
        $this->afterRelease[$key][] = $callback;
    }

    private function lockKey(string $runId): string
    {
        return \sprintf('agent_loop.run.%s', $runId);
    }
}
