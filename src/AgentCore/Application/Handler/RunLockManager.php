<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class RunLockManager
{
    /** @var array<string, LockInterface> */
    private array $activeLocks = [];

    private readonly string $cwdNamespace;

    public function __construct(
        private LockFactory $lockFactory,
        ?string $cwdNamespace = null,
        private float $ttlSeconds = 30.0,
        private float $acquireTimeoutSeconds = 5.0,
    ) {
        $this->cwdNamespace = self::normalizeCwdNamespace($cwdNamespace);
    }

    /**
     * Canonical Hatfield project/worktree identity for lock namespacing.
     *
     * FlockStore locks live in the system temp directory and are global to the
     * host, so run IDs alone are insufficient across checkouts/worktrees.
     */
    public static function normalizeCwdNamespace(?string $cwdNamespace): string
    {
        $candidate = $cwdNamespace;
        if (null === $candidate || '' === $candidate) {
            $envCwd = $_ENV['HATFIELD_CWD'] ?? null;
            if (null === $envCwd) {
                $getenvCwd = getenv('HATFIELD_CWD');
                $envCwd = false !== $getenvCwd ? $getenvCwd : null;
            }
            $candidate = $envCwd;
        }
        if (null === $candidate || '' === $candidate) {
            $cwd = getcwd();
            $candidate = false !== $cwd ? $cwd : '';
        }

        if ('' === $candidate) {
            throw new \RuntimeException('Unable to resolve run lock CWD namespace. Set HATFIELD_CWD or pass an explicit cwd namespace.');
        }

        $realpath = realpath($candidate);

        return false !== $realpath ? $realpath : $candidate;
    }

    public static function lockResourceKey(string $cwdNamespace, string $runId): string
    {
        $normalized = self::normalizeCwdNamespace($cwdNamespace);

        return \sprintf(
            'agent_loop.cwd.%s.run.%s',
            hash('sha256', $normalized),
            $runId,
        );
    }

    /**
     * Acquires a per-run lock scoped to the Hatfield project CWD namespace.
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
        }
    }

    private function lockKey(string $runId): string
    {
        return self::lockResourceKey($this->cwdNamespace, $runId);
    }
}
