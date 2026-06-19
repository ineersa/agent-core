<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Cross-process-safe CommandStore backed by the Symfony cache pool.
 *
 * The cache pool is configured as cache.adapter.doctrine_dbal (shared SQLite)
 * in framework.yaml, which is reachable from all messenger consumer processes
 * (run_control, llm, tool). This fixes the InMemoryCommandStore limitation
 * where enqueue in run_control was invisible to the stop-boundary drain in
 * the llm process, and where consumer restarts wiped the in-memory queue.
 *
 * Data shape per run (stored as a single cache item, keyed by runId):
 *   ['commands'  => [idempotencyKey => PendingCommand],
 *    'statuses'  => [idempotencyKey => 'pending'|'applied'|'rejected:…'|'superseded:…'],
 *    'order'     => [idempotencyKey, …]]  // FIFO insertion order
 *
 * All mutating operations are guarded by a LockFactory FlockStore lock
 * keyed by run, mirroring SessionRunStore's compareAndSwap pattern.
 *
 * @see CommandStoreInterface
 * @see InMemoryCommandStore — single-process reference implementation
 * @see \Ineersa\CodingAgent\Session\SessionRunStore — reference CAS+lock pattern
 */
final class CacheCommandStore implements CommandStoreInterface
{
    private const string CACHE_KEY_PREFIX = 'hatfield.command.';
    private const string LOCK_KEY_PREFIX = 'hatfield-command-';

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function enqueue(PendingCommand $command): bool
    {
        // Fast-reject: non-authoritative hint outside the lock.
        // Two concurrent enqueues of the same key can both pass this check —
        // the authoritative check is inside the lock below.
        if ($this->has($command->runId, $command->idempotencyKey)) {
            return false;
        }

        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$command->runId);
        $lock->acquire(true);

        try {
            $data = $this->load($command->runId);

            // Authoritative idempotency check under the lock.
            // Between the fast-reject has()→false above and acquiring the
            // lock, another process may have enqueued the same key.  The
            // check-and-write inside the lock is atomic — only one process
            // will observe the key absent and proceed to write.  Without
            // this, both processes could pass the outer has(), then the
            // second would blindly overwrite commands[k]/statuses[k] and
            // append a duplicate order[] entry, violating the idempotency
            // guarantee.
            if (isset($data['statuses'][$command->idempotencyKey])) {
                return false;
            }

            $data['commands'][$command->idempotencyKey] = $command;
            $data['statuses'][$command->idempotencyKey] = 'pending';
            $data['order'][] = $command->idempotencyKey;

            $this->save($command->runId, $data);

            return true;
        } finally {
            $lock->release();
        }
    }

    public function has(string $runId, string $idempotencyKey): bool
    {
        $data = $this->load($runId);

        return isset($data['statuses'][$idempotencyKey]);
    }

    public function pending(string $runId): array
    {
        $data = $this->load($runId);

        $pending = [];

        foreach ($data['order'] ?? [] as $idempotencyKey) {
            if ('pending' !== ($data['statuses'][$idempotencyKey] ?? null)) {
                continue;
            }

            $command = $data['commands'][$idempotencyKey] ?? null;
            if (null === $command) {
                continue;
            }

            $pending[] = $command;
        }

        return $pending;
    }

    public function countPending(string $runId): int
    {
        return \count($this->pending($runId));
    }

    public function rejectPendingByKind(string $runId, string $kind, string $reason): array
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
        $lock->acquire(true);

        try {
            $data = $this->load($runId);
            $rejected = [];

            foreach ($data['order'] ?? [] as $idempotencyKey) {
                if ('pending' !== ($data['statuses'][$idempotencyKey] ?? null)) {
                    continue;
                }

                $command = $data['commands'][$idempotencyKey] ?? null;
                if (null === $command || $command->kind !== $kind) {
                    continue;
                }

                $data['statuses'][$idempotencyKey] = 'rejected: '.$reason;
                $rejected[] = $command;
            }

            $this->save($runId, $data);

            return $rejected;
        } finally {
            $lock->release();
        }
    }

    public function markApplied(string $runId, string $idempotencyKey): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
        $lock->acquire(true);

        try {
            $data = $this->load($runId);
            $data['statuses'][$idempotencyKey] = 'applied';
            $this->save($runId, $data);
        } finally {
            $lock->release();
        }
    }

    public function markRejected(string $runId, string $idempotencyKey, string $reason): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
        $lock->acquire(true);

        try {
            $data = $this->load($runId);
            $data['statuses'][$idempotencyKey] = 'rejected: '.$reason;
            $this->save($runId, $data);
        } finally {
            $lock->release();
        }
    }

    public function markSuperseded(string $runId, string $idempotencyKey, string $reason): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
        $lock->acquire(true);

        try {
            $data = $this->load($runId);
            $data['statuses'][$idempotencyKey] = 'superseded: '.$reason;
            $this->save($runId, $data);
        } finally {
            $lock->release();
        }
    }

    /**
     * Load command data for a run from the cache pool.
     *
     * Returns the canonical empty shape when no cached data exists,
     * so callers always receive a well-formed array.
     *
     * PendingCommand DTOs are stored directly and round-trip through
     * the cache pool's native PHP serialization (Doctrine DBAL adapter).
     * readonly properties on final classes serialize correctly since
     * PHP 8.2 — no Serializable interface or manual normalize/denormalize
     * is needed at the store boundary.
     *
     * @return array{commands: array<string, PendingCommand>, statuses: array<string, string>, order: list<string>}
     */
    private function load(string $runId): array
    {
        $key = self::CACHE_KEY_PREFIX.$runId;
        $item = $this->pool->getItem($key);

        if (!$item->isHit()) {
            return [
                'commands' => [],
                'statuses' => [],
                'order' => [],
            ];
        }

        $raw = $item->get();

        if (!\is_array($raw)) {
            return [
                'commands' => [],
                'statuses' => [],
                'order' => [],
            ];
        }

        return [
            'commands' => $raw['commands'] ?? [],
            'statuses' => $raw['statuses'] ?? [],
            'order' => $raw['order'] ?? [],
        ];
    }

    /**
     * Save command data for a run to the cache pool.
     *
     * The data array (including PendingCommand objects) is stored as-is;
     * the Doctrine DBAL cache adapter handles PHP serialization to the
     * cache_items.item_data BLOB column. No expiration is set — commands
     * live for the lifetime of the run and are naturally removed when the
     * run completes (the cache item may persist but is no longer accessed).
     *
     * @param array{commands: array<string, PendingCommand>, statuses: array<string, string>, order: list<string>} $data
     */
    private function save(string $runId, array $data): void
    {
        $key = self::CACHE_KEY_PREFIX.$runId;

        // Recreate the item each time to avoid stale-tag issues with
        // the adapter's internal tracking — getItem() followed by set()
        // is the standard PSR-6 write pattern.
        $item = $this->pool->getItem($key);
        $item->set($data);

        // null = never expires. The item lives for the duration of the
        // run; after the run completes, it is no longer accessed and
        // does not cause unbounded growth (cache_items has a TTL-based
        // prune mechanism via item_lifetime; null means "no pruning").
        $item->expiresAfter(null);

        if (!$this->pool->save($item)) {
            throw new \RuntimeException(\sprintf('Failed to persist command store data for run %s.', $runId));
        }
    }
}
