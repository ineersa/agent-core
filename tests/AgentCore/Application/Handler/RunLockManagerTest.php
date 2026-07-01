<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class RunLockManagerTest extends TestCase
{
    private const string CWD_A = '/tmp/hatfield-lock-test/project-a';
    private const string CWD_B = '/tmp/hatfield-lock-test/project-b';

    public function testSynchronizedExecutesCriticalSectionAndReturnsValue(): void
    {
        $manager = new RunLockManager(new LockFactory(new InMemoryStore()), self::CWD_A);

        $result = $manager->synchronized('run-lock-1', static fn (): string => 'ok');

        self::assertSame('ok', $result);
    }

    public function testSynchronizedFailsFastWhenAnotherWorkerOwnsLockInSameCwdNamespace(): void
    {
        $store = new InMemoryStore();
        $factory = new LockFactory($store);

        $stranded = $factory->createLock(
            RunLockManager::lockResourceKey(self::CWD_A, 'run-lock-2'),
            30.0,
            autoRelease: false,
        );
        self::assertTrue($stranded->acquire());

        $manager = new RunLockManager($factory, self::CWD_A, ttlSeconds: 30.0, acquireTimeoutSeconds: 0.05);

        $startedAt = microtime(true);

        try {
            $manager->synchronized('run-lock-2', static fn (): string => 'should-not-run');
            self::fail('Expected lock acquisition timeout exception.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Failed to acquire run lock for "run-lock-2"', $exception->getMessage());
        } finally {
            if ($stranded->isAcquired()) {
                $stranded->release();
            }
        }

        $elapsedSeconds = microtime(true) - $startedAt;
        self::assertLessThan(1.0, $elapsedSeconds);
    }

    public function testSameRunIdDifferentCwdNamespacesDoNotConflict(): void
    {
        $store = new InMemoryStore();
        $factory = new LockFactory($store);

        $managerA = new RunLockManager($factory, self::CWD_A);
        $managerB = new RunLockManager($factory, self::CWD_B);

        $resultA = null;
        $resultB = null;

        $managerA->synchronized('shared-run-id', function () use ($managerB, &$resultA, &$resultB): void {
            $resultA = 'a';
            $resultB = $managerB->synchronized('shared-run-id', static fn (): string => 'b');
        });

        self::assertSame('a', $resultA);
        self::assertSame('b', $resultB);
    }

    /**
     * Re-entrant guard: nested synchronized() calls for the same
     * runId must NOT deadlock.
     */
    public function testReentrantSynchronizedSameRunIdDoesNotDeadlock(): void
    {
        $manager = new RunLockManager(new LockFactory(new InMemoryStore()), self::CWD_A);

        $outerRan = false;
        $innerRan = false;

        $manager->synchronized('reentrant-run', function () use ($manager, &$outerRan, &$innerRan): void {
            $outerRan = true;

            $manager->synchronized('reentrant-run', function () use (&$innerRan): void {
                $innerRan = true;
            });
        });

        self::assertTrue($outerRan, 'Outer critical section must execute');
        self::assertTrue($innerRan, 'Inner (re-entrant) critical section must execute');
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
            self::CWD_A,
            ttlSeconds: 30.0,
            acquireTimeoutSeconds: 0.05,
        );

        $extLock = (new LockFactory($store))->createLock(
            RunLockManager::lockResourceKey(self::CWD_A, 'run-a'),
            30.0,
            autoRelease: false,
        );
        self::assertTrue($extLock->acquire());

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed to acquire run lock for "run-a"');
            $manager->synchronized('run-a', static fn (): string => 'nope');
        } finally {
            $extLock->release();
        }
    }
}
