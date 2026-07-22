<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Messenger\OmParentDeathListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;

/**
 * Thesis: OM worker stops only when the exact configured parent PID is gone.
 */
final class OmParentDeathListenerTest extends TestCase
{
    public function testDoesNotStopWhileParentAlive(): void
    {
        $worker = new Worker([], new MessageBus());
        $listener = new OmParentDeathListener(getmypid(), new NullLogger());
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));

        $this->assertFalse($this->shouldStop($worker));
    }

    public function testStopsWhenParentMissing(): void
    {
        // Never use PID 1. Prefer a high unused PID; fall back if somehow present.
        $deadPid = 2_147_483_646;
        if (is_dir('/proc/'.$deadPid)) {
            $this->markTestSkipped('Unexpected live PID '.$deadPid);
        }

        $worker = new Worker([], new MessageBus());
        $listener = new OmParentDeathListener($deadPid, new NullLogger());
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));

        $this->assertTrue($this->shouldStop($worker));

        // Second idle tick must not re-enter stop logic (idempotent flag).
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
        $this->assertTrue($this->shouldStop($worker));
    }

    public function testRejectsInitParentPid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OmParentDeathListener(1, new NullLogger());
    }

    private function shouldStop(Worker $worker): bool
    {
        $ref = new \ReflectionProperty(Worker::class, 'shouldStop');

        return (bool) $ref->getValue($worker);
    }
}
