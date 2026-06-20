<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\Storage;

use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Infrastructure\Storage\CacheCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;

/**
 * Contract test proving cross-process visibility of CacheCommandStore.
 *
 * Two CacheCommandStore instances sharing the same Doctrine DBAL cache pool
 * (cache.app) must see each other's enqueues, rejections, and status marks.
 * The same test would FAIL for InMemoryCommandStore because each instance
 * has its own private in-process array.
 *
 * This test boots the Symfony kernel (APP_ENV=test) to obtain the real
 * cache.app pool and LockFactory. The cache_items table is created by
 * the migration Version20260617141001 which runs before the test suite.
 *
 * @covers \Ineersa\AgentCore\Infrastructure\Storage\CacheCommandStore
 */
#[CoversClass(CacheCommandStore::class)]
final class CacheCommandStoreTest extends KernelTestCase
{
    private CacheCommandStore $storeA;
    private CacheCommandStore $storeB;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => false]);

        /** @var CacheItemPoolInterface */
        $pool = static::getContainer()->get('cache.app');

        /** @var LockFactory */
        $lockFactory = static::getContainer()->get(LockFactory::class);

        // Two store instances sharing the same backing pool — simulating
        // the enqueue (run_control) and drain (llm) consumer processes.
        $this->storeA = new CacheCommandStore($pool, $lockFactory);
        $this->storeB = new CacheCommandStore($pool, $lockFactory);

        // Clean any residual cache data from prior test runs.
        // DAMA transaction rollback covers ORM but not raw cache_items.
        $pool->deleteItem('hatfield.command.test-cross-1');
        $pool->deleteItem('hatfield.command.test-cross-2');
    }

    protected function tearDown(): void
    {
        // Let KernelTestCase::tearDown() handle kernel shutdown.
        // It calls ensureKernelShutdown() which may re-boot the kernel,
        // which re-registers the exception handler.
        parent::tearDown();
        // Pop the exception handler that FrameworkBundle::boot() registered
        // during kernel boot/shutdown. Without this, PHPUnit reports
        // "Test code or tested code did not remove its own exception
        // handlers" (risky) on every test method. Mirrors TraceReplayTest.
        restore_exception_handler();
    }

    /**
     * InMemoryCommandStore would fail this assertion because each instance
     * has its own private PHP array — cross-instance visibility is impossible.
     */
    public function testInMemoryStoreFailsCrossInstanceVisibility(): void
    {
        $inMemoryA = new InMemoryCommandStore();
        $inMemoryB = new InMemoryCommandStore();

        $command = new PendingCommand(
            runId: 'test-cross-mem',
            kind: 'follow_up',
            idempotencyKey: 'key-mem-1',
            payload: ['text' => 'hello'],
        );

        $inMemoryA->enqueue($command);

        // Instance B should NOT see the command enqueued in instance A.
        self::assertEmpty(
            $inMemoryB->pending('test-cross-mem'),
            'InMemoryCommandStore fails cross-instance visibility — this is the bug CacheCommandStore fixes',
        );
    }

    /**
     * The core contract: a command enqueued in one store instance
     * is visible to pending() in another instance sharing the same pool.
     */
    public function testCrossInstanceEnqueueVisible(): void
    {
        $command = new PendingCommand(
            runId: 'test-cross-1',
            kind: 'steer',
            idempotencyKey: 'key-1',
            payload: ['text' => 'steer message'],
        );

        $enqueued = $this->storeA->enqueue($command);
        self::assertTrue($enqueued, 'Should enqueue successfully');

        // Instance B sees the command.
        $pending = $this->storeB->pending('test-cross-1');
        self::assertCount(1, $pending);
        self::assertSame('steer', $pending[0]->kind);
        self::assertSame('key-1', $pending[0]->idempotencyKey);
        self::assertSame(['text' => 'steer message'], $pending[0]->payload);
    }

    public function testCrossInstanceHasVisible(): void
    {
        $command = new PendingCommand(
            runId: 'test-cross-1',
            kind: 'follow_up',
            idempotencyKey: 'key-2',
        );

        $this->storeA->enqueue($command);

        self::assertTrue($this->storeB->has('test-cross-1', 'key-2'));
    }

    public function testCrossInstanceCountPendingVisible(): void
    {
        $this->storeA->enqueue(new PendingCommand(
            runId: 'test-cross-1',
            kind: 'follow_up',
            idempotencyKey: 'key-count-1',
        ));
        $this->storeA->enqueue(new PendingCommand(
            runId: 'test-cross-1',
            kind: 'follow_up',
            idempotencyKey: 'key-count-2',
        ));

        self::assertSame(2, $this->storeB->countPending('test-cross-1'));
    }

    public function testCrossInstanceRejectPendingByKindVisible(): void
    {
        $this->storeA->enqueue(new PendingCommand(
            runId: 'test-cross-1',
            kind: 'steer',
            idempotencyKey: 'key-reject-1',
        ));
        $this->storeA->enqueue(new PendingCommand(
            runId: 'test-cross-1',
            kind: 'follow_up',
            idempotencyKey: 'key-reject-2',
        ));

        // Reject steers from instance A.
        $rejected = $this->storeA->rejectPendingByKind('test-cross-1', 'steer', 'cancelled');

        self::assertCount(1, $rejected);
        self::assertSame('steer', $rejected[0]->kind);

        // Instance B sees only the follow_up still pending.
        $pending = $this->storeB->pending('test-cross-1');
        self::assertCount(1, $pending);
        self::assertSame('follow_up', $pending[0]->kind);
    }

    public function testCrossInstanceMarkAppliedVisible(): void
    {
        $comm = new PendingCommand(
            runId: 'test-cross-1',
            kind: 'continue',
            idempotencyKey: 'key-mark-applied',
        );
        $this->storeA->enqueue($comm);

        // Mark applied from instance B.
        $this->storeB->markApplied('test-cross-1', 'key-mark-applied');

        // Instance A sees it as no longer pending.
        self::assertEmpty($this->storeA->pending('test-cross-1'));
        // Instance B confirms it's still tracked (has = true) but not pending.
        self::assertTrue($this->storeB->has('test-cross-1', 'key-mark-applied'));
    }

    public function testCrossInstanceMarkRejectedVisible(): void
    {
        $comm = new PendingCommand(
            runId: 'test-cross-1',
            kind: 'steer',
            idempotencyKey: 'key-mark-rejected',
        );
        $this->storeA->enqueue($comm);

        $this->storeB->markRejected('test-cross-1', 'key-mark-rejected', 'abandoned');

        // Instance A confirms it's no longer pending.
        self::assertEmpty($this->storeA->pending('test-cross-1'));
        self::assertTrue($this->storeA->has('test-cross-1', 'key-mark-rejected'));
    }

    public function testCrossInstanceMarkSupersededVisible(): void
    {
        $comm = new PendingCommand(
            runId: 'test-cross-1',
            kind: 'follow_up',
            idempotencyKey: 'key-mark-superseded',
        );
        $this->storeA->enqueue($comm);

        $this->storeB->markSuperseded('test-cross-1', 'key-mark-superseded', 'replaced');

        self::assertEmpty($this->storeA->pending('test-cross-1'));
    }

    public function testIdempotencyKeyDeduplicationWorksCrossInstance(): void
    {
        $comm = new PendingCommand(
            runId: 'test-cross-2',
            kind: 'steer',
            idempotencyKey: 'dup-key',
        );

        self::assertTrue($this->storeA->enqueue($comm));

        // Duplicate enqueue from different instance should fail.
        self::assertFalse($this->storeB->enqueue($comm));
    }

    public function testPendingFifoOrder(): void
    {
        $this->storeA->enqueue(new PendingCommand(
            runId: 'test-cross-2',
            kind: 'steer',
            idempotencyKey: 'order-1',
        ));
        $this->storeB->enqueue(new PendingCommand(
            runId: 'test-cross-2',
            kind: 'follow_up',
            idempotencyKey: 'order-2',
        ));

        $pending = $this->storeA->pending('test-cross-2');
        self::assertCount(2, $pending);
        self::assertSame('order-1', $pending[0]->idempotencyKey, 'FIFO: first in, first out');
        self::assertSame('order-2', $pending[1]->idempotencyKey);
    }

    /**
     * CacheCommandStore must not silently swallow persistence failures — it
     * throws so a lost enqueue/marking is visible instead of silently
     * dropping a queued command (the cross-process visibility contract this
     * store exists to enforce).
     */
    public function testPersistenceFailureThrowsRuntimeException(): void
    {
        $realPool = static::getContainer()->get('cache.app');
        $lockFactory = static::getContainer()->get(LockFactory::class);

        // Decorator that delegates everything to the real pool except
        // save(), which returns false to simulate disk-full / DB-locked
        // / adapter-error conditions.
        $failingPool = new class($realPool) implements CacheItemPoolInterface {
            public function __construct(
                private readonly CacheItemPoolInterface $inner,
            ) {
            }

            public function getItem(string $key): CacheItemInterface
            {
                return $this->inner->getItem($key);
            }

            public function getItems(array $keys = []): iterable
            {
                return $this->inner->getItems($keys);
            }

            public function hasItem(string $key): bool
            {
                return $this->inner->hasItem($key);
            }

            public function clear(): bool
            {
                return $this->inner->clear();
            }

            public function deleteItem(string $key): bool
            {
                return $this->inner->deleteItem($key);
            }

            public function deleteItems(array $keys): bool
            {
                return $this->inner->deleteItems($keys);
            }

            public function save(CacheItemInterface $item): bool
            {
                return false;
            }

            public function saveDeferred(CacheItemInterface $item): bool
            {
                return $this->inner->saveDeferred($item);
            }

            public function commit(): bool
            {
                return $this->inner->commit();
            }
        };

        $failingStore = new CacheCommandStore($failingPool, $lockFactory);

        $command = new PendingCommand(
            runId: 'test-persist-fail',
            kind: 'steer',
            idempotencyKey: 'key-persist-fail',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Failed to persist command store data for run test-persist-fail.',
        );

        $failingStore->enqueue($command);
    }

    protected static function createKernel(array $options = []): \Ineersa\CodingAgent\Kernel
    {
        $env = $options['environment'] ?? 'test';
        $debug = (bool) ($options['debug'] ?? false);

        return new \Ineersa\CodingAgent\Kernel($env, $debug);
    }
}
