<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class RunTracerTest extends TestCase
{
    public function testInterleavedFibersKeepTheirParentsAndUnwindIndependently(): void
    {
        $logger = new TestLogger();
        $tracer = new RunTracer($logger);
        $failure = new \RuntimeException('cancel A');
        $a = new \Fiber(static function () use ($tracer, $failure): void {
            try {
                $tracer->inSpan('a', [], static function () use ($tracer): void {
                    \Fiber::suspend();
                    $tracer->inSpan('a.child', [], static function (): void { \Fiber::suspend(); });
                });
                self::fail('The injected failure must propagate.');
            } catch (\RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            $tracer->inSpan('a.after', [], static fn (): string => 'done');
        });
        $b = new \Fiber(static function () use ($tracer): void {
            $tracer->inSpan('b', [], static function () use ($tracer): void {
                \Fiber::suspend();
                $tracer->inSpan('b.child', [], static fn (): string => 'done');
            });
        });
        $a->start();
        $b->start();
        $tracer->inSpan('main', [], static fn (): string => 'done');
        $a->resume();
        $a->throw($failure);
        $b->resume();

        $starts = [];
        foreach ($logger->records as $record) {
            if ('agent_loop.trace.start' === $record['message']) {
                $starts[$record['context']['span_name']] = $record['context'];
            }
        }
        foreach (['a', 'b', 'main', 'a.after'] as $name) {
            $this->assertNull($starts[$name]['parent_span_id'], $name.' must have no unrelated parent.');
        }
        $this->assertSame($starts['a']['span_id'], $starts['a.child']['parent_span_id']);
        $this->assertSame($starts['b']['span_id'], $starts['b.child']['parent_span_id']);
        $this->assertTrue($a->isTerminated());
        $this->assertTrue($b->isTerminated());
        $weakA = \WeakReference::create($a);
        $weakB = \WeakReference::create($b);
        unset($a, $b);
        $this->assertNull($weakA->get());
        $this->assertNull($weakB->get());
    }

    public function testEmitsStartAndFinishRecords(): void
    {
        $logger = new TestLogger();
        $tracer = new RunTracer($logger);

        $result = $tracer->inSpan('test.noop', [], static fn (): string => 'hello');

        $this->assertSame('hello', $result);

        $records = $logger->records;
        $this->assertCount(2, $records);
        $this->assertSame('agent_loop.trace.start', $records[0]['message']);
        $this->assertSame('agent_loop.trace.finish', $records[1]['message']);
        $this->assertSame(['debug', 'debug'], array_column($records, 'level'));

        $finishContext = $records[1]['context'];
        $this->assertSame('ok', $finishContext['status']);
        $this->assertArrayHasKey('duration_ms', $finishContext);
    }

    public function testFinishRecordMarksErrorStatusWhenOperationThrows(): void
    {
        $logger = new TestLogger();
        $tracer = new RunTracer($logger);

        $this->expectException(\RuntimeException::class);

        try {
            $tracer->inSpan('test.error', ['run_id' => 'run-1'], static function (): never {
                throw new \RuntimeException('test error');
            });
        } finally {
            $finishContext = $logger->records[1]['context'];
            $this->assertSame('error', $finishContext['status']);
            $this->assertSame('test.error', $finishContext['span_name']);
        }
    }

    public function testNestedSpansSetParentSpanIdInLogRecords(): void
    {
        $logger = new TestLogger();
        $tracer = new RunTracer($logger);

        $tracer->inSpan('parent', [], static function () use ($tracer): void {
            $tracer->inSpan('child', ['step_id' => 'step-1'], static fn (): string => 'done');
        });

        $childStart = $logger->records[1];
        $this->assertSame('agent_loop.trace.start', $childStart['message']);
        $this->assertSame('child', $childStart['context']['span_name']);
        $this->assertSame('span-1', $childStart['context']['parent_span_id']);
    }

    public function testRootSpanDoesNotSetParentInLogRecords(): void
    {
        $logger = new TestLogger();
        $tracer = new RunTracer($logger);

        $tracer->inSpan('root.op', ['run_id' => 'run-1'], static fn (): string => 'ok', root: true);

        $startRecord = $logger->records[0];
        $this->assertNull($startRecord['context']['parent_span_id']);
    }

    public function testDebugSpanPreservesInfoParentAndErrorUnwinding(): void
    {
        $logger = new TestLogger();
        $tracer = new RunTracer($logger);
        $failure = new \RuntimeException('diagnostic failure');

        $tracer->inSpan('owner', [], static function () use ($tracer, $failure): void {
            try {
                $tracer->inSpan('detail', [], static function () use ($failure): never {
                    throw $failure;
                }, level: LogLevel::DEBUG);
                self::fail('The debug operation must propagate its exception.');
            } catch (\RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }

            self::assertSame('value', $tracer->inSpan('next', [], static fn (): string => 'value', level: LogLevel::INFO));
        }, level: LogLevel::INFO);

        $this->assertSame(['info', 'debug', 'debug', 'info', 'info', 'info'], array_column($logger->records, 'level'));
        $this->assertSame('span-1', $logger->records[1]['context']['parent_span_id']);
        $this->assertSame('error', $logger->records[2]['context']['status']);
        $this->assertSame('span-1', $logger->records[3]['context']['parent_span_id']);
        $this->assertSame('ok', $logger->records[5]['context']['status']);
    }

    public function testExplicitInfoLevelPreservesMetricSpansAtInfo(): void
    {
        $logger = new TestLogger();
        $tracer = new RunTracer($logger);

        $tracer->inSpan('tool.call', ['tool_name' => 'bash'], static fn (): string => 'ok', level: LogLevel::INFO);

        $this->assertSame(['info', 'info'], array_column($logger->records, 'level'));
        $this->assertSame('tool.call', $logger->records[1]['context']['span_name']);
        $this->assertSame('ok', $logger->records[1]['context']['status']);
    }
}
