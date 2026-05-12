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

        self::assertSame('ok', $result);
    }

    public function testSynchronizedFailsFastWhenAnotherWorkerOwnsLock(): void
    {
        $store = new InMemoryStore();
        $factory = new LockFactory($store);

        $stranded = $factory->createLock('agent_loop.run.run-lock-2', 30.0, autoRelease: false);
        self::assertTrue($stranded->acquire());

        $manager = new RunLockManager($factory, ttlSeconds: 30.0, acquireTimeoutSeconds: 0.05);

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
}
