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
 * Thesis: parent-death listener stops the worker only when the exact parent PID is gone.
 *
 * Uses a real Worker instance (Worker is @final and cannot be PHPUnit-mocked under
 * fail-on-all-issues).
 */
final class OmParentDeathListenerTest extends TestCase
{
    public function testIgnoresMissingParentConfiguration(): void
    {
        $worker = new Worker([], new MessageBus());
        $listener = new OmParentDeathListener(new NullLogger(), 0);
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));

        $shouldStop = new \ReflectionProperty($worker, 'shouldStop');
        $this->assertFalse($shouldStop->getValue($worker));
    }

    public function testStopsWhenParentMissing(): void
    {
        // Extremely high unused PID — /proc probe should report absent.
        $deadPid = 2_147_000_000;
        if (is_dir('/proc/'.$deadPid)) {
            $this->markTestSkipped('Unexpected live /proc entry for synthetic dead PID.');
        }

        $worker = new Worker([], new MessageBus());
        $listener = new OmParentDeathListener(new NullLogger(), $deadPid);
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));

        $shouldStop = new \ReflectionProperty($worker, 'shouldStop');
        $this->assertTrue($shouldStop->getValue($worker));
    }
}
