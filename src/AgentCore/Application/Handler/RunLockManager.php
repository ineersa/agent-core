<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Fiber;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

use function Amp\delay;

final class RunLockManager
{
    /** @var array<string, array{lock: LockInterface, owner: ?\Fiber<mixed, mixed, mixed, mixed>}> */
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
     * Supports re-entrant calls: if this execution context already holds the
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

        // Reentrancy belongs to the calling Fiber, not the shared process.
        $owner = \Fiber::getCurrent();
        if (isset($this->activeLocks[$key]) && $this->activeLocks[$key]['owner'] === $owner) {
            return $criticalSection();
        }

        $lock = $this->lockFactory->createLock($key, $this->ttlSeconds, autoRelease: false);

        $acquireUntil = microtime(true) + max(0.001, $this->acquireTimeoutSeconds);

        while (isset($this->activeLocks[$key]) || !$lock->acquire()) {
            if (microtime(true) >= $acquireUntil) {
                throw new \RuntimeException(\sprintf('Failed to acquire run lock for "%s" within %.3f seconds.', $runId, $this->acquireTimeoutSeconds));
            }

            delay(0.02);
        }

        $this->activeLocks[$key] = ['lock' => $lock, 'owner' => $owner];

        try {
            return $criticalSection();
        } finally {
            $callbacks = $this->afterRelease[$key] ?? [];
            unset($this->afterRelease[$key]);
            unset($this->activeLocks[$key]);

            if ($lock->isAcquired()) {
                $lock->release();
            }

            foreach ($callbacks as $callback) {
                $callback();
            }
        }
    }

    /**
     * Runs after this execution context releases its outermost lock for the run.
     * Nested synchronized sections must not observe broker side effects early.
     *
     * @param callable(): void $callback
     */
    public function afterRelease(string $runId, callable $callback): void
    {
        $key = $this->lockKey($runId);
        if (!isset($this->activeLocks[$key]) || $this->activeLocks[$key]['owner'] !== \Fiber::getCurrent()) {
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
