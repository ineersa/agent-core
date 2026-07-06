<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class RunLockManagerTest extends TestCase
{
    public function testSynchronizedExecutesCriticalSectionAndReturnsValue(): void
    {
        $manager = new RunLockManager(new LockFactory(new InMemoryStore()));

        $result = $manager->synchronized('run-lock-1', static fn (): string => 'ok');

        $this->assertSame('ok', $result);
    }

    public function testSynchronizedFailsFastWhenAnotherWorkerOwnsLock(): void
    {
        $store = new InMemoryStore();
        $factory = new LockFactory($store);

        $stranded = $factory->createLock('agent_loop.run.run-lock-2', 30.0, autoRelease: false);
        $this->assertTrue($stranded->acquire());

        $manager = new RunLockManager($factory, ttlSeconds: 30.0, acquireTimeoutSeconds: 0.05);

        $startedAt = microtime(true);

        try {
            $manager->synchronized('run-lock-2', static fn (): string => 'should-not-run');
            $this->fail('Expected lock acquisition timeout exception.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to acquire run lock for "run-lock-2"', $exception->getMessage());
        } finally {
            if ($stranded->isAcquired()) {
                $stranded->release();
            }
        }

        $elapsedSeconds = microtime(true) - $startedAt;
        $this->assertLessThan(1.0, $elapsedSeconds);
    }

    /**
     * Re-entrant guard: nested synchronized() calls for the same
     * runId must NOT deadlock.
     *
     * Without the guard, StartRunHandler dispatches an initial
     * AdvanceRun via post-commit callback inside the synchronized()
     * block, causing the second process() call to attempt re-acquiring
     * the same lock → deadlock with FlockStore.
     */
    public function testReentrantSynchronizedSameRunIdDoesNotDeadlock(): void
    {
        $manager = new RunLockManager(new LockFactory(new InMemoryStore()));

        $outerRan = false;
        $innerRan = false;

        $manager->synchronized('reentrant-run', static function () use ($manager, &$outerRan, &$innerRan): void {
            $outerRan = true;

            // Nested call for the SAME runId — must not deadlock.
            $manager->synchronized('reentrant-run', static function () use (&$innerRan): void {
                $innerRan = true;
            });
        });

        $this->assertTrue($outerRan, 'Outer critical section must execute');
        $this->assertTrue($innerRan, 'Inner (re-entrant) critical section must execute');
    }

    /**
     * Different run IDs must still lock independently — the
     * re-entrant guard must not collapse separate locks.
     */
    public function testSynchronizedDifferentRunIdsLockIndependently(): void
    {
        $store = new InMemoryStore();
        $manager = new RunLockManager(
            new LockFactory($store),
            ttlSeconds: 30.0,
            acquireTimeoutSeconds: 0.05,
        );

        // Pre-acquire one lock externally to simulate contention.
        $extLock = (new LockFactory($store))->createLock('agent_loop.run.run-a', 30.0, autoRelease: false);
        $this->assertTrue($extLock->acquire());

        try {
            // run-a should fail (externally held) ...
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed to acquire run lock for "run-a"');
            $manager->synchronized('run-a', static fn (): string => 'nope');
        } finally {
            $extLock->release();
        }
    }
}
