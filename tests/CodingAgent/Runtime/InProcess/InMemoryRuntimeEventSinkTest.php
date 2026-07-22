<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryRuntimeEventSink::class)]
final class InMemoryRuntimeEventSinkTest extends TestCase
{
    #[Test]
    public function drainYieldsMatchingRunEvents(): void
    {
        $sink = new InMemoryRuntimeEventSink();
        $e1 = new RuntimeEvent('test.type', 'run-1', 0);
        $e2 = new RuntimeEvent('test.type', 'run-1', 0);

        $sink->emit($e1);
        $sink->emit($e2);

        $drained = iterator_to_array($sink->drain('run-1'));
        $this->assertCount(2, $drained);
        $this->assertSame($e1, $drained[0]);
        $this->assertSame($e2, $drained[1]);
    }

    #[Test]
    public function drainIsDestructive(): void
    {
        $sink = new InMemoryRuntimeEventSink();
        $sink->emit(new RuntimeEvent('test.type', 'run-1', 0));

        iterator_to_array($sink->drain('run-1'));
        $second = iterator_to_array($sink->drain('run-1'));

        $this->assertCount(0, $second);
    }

    #[Test]
    public function drainFiltersByRunId(): void
    {
        $sink = new InMemoryRuntimeEventSink();
        $e1 = new RuntimeEvent('test.type', 'run-1', 0);
        $e2 = new RuntimeEvent('test.type', 'run-2', 0);

        $sink->emit($e1);
        $sink->emit($e2);

        $drained = iterator_to_array($sink->drain('run-1'));
        $this->assertCount(1, $drained);
        $this->assertSame($e1, $drained[0]);
    }

    #[Test]
    public function drainKeepsEventsForOtherRuns(): void
    {
        $sink = new InMemoryRuntimeEventSink();
        $sink->emit(new RuntimeEvent('test.type', 'run-1', 0));
        $sink->emit(new RuntimeEvent('test.type', 'run-2', 0));

        // Drain run-1 only
        iterator_to_array($sink->drain('run-1'));

        // run-2 events should still be buffered
        $drained = iterator_to_array($sink->drain('run-2'));
        $this->assertCount(1, $drained);
        $this->assertSame('run-2', $drained[0]->runId);
    }

    #[Test]
    public function drainWithEmptyRunIdReturnsAll(): void
    {
        $sink = new InMemoryRuntimeEventSink();
        $sink->emit(new RuntimeEvent('test.type', 'run-1', 0));
        $sink->emit(new RuntimeEvent('test.type', 'run-2', 0));

        $drained = iterator_to_array($sink->drain(''));
        $this->assertCount(2, $drained);
    }
}
