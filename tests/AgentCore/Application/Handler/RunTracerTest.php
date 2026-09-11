<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\RunTracer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class RunTracerTest extends TestCase
{
    public function testEmitsStartAndFinishRecords(): void
    {
        $logger = new TraceLogger();
        $tracer = new RunTracer($logger);

        $result = $tracer->inSpan('test.noop', [], static fn (): string => 'hello');

        $this->assertSame('hello', $result);

        $records = $logger->records;
        $this->assertCount(2, $records);
        $this->assertSame('agent_loop.trace.start', $records[0]['message']);
        $this->assertSame('agent_loop.trace.finish', $records[1]['message']);

        $finishContext = $records[1]['context'];
        $this->assertSame('ok', $finishContext['status']);
        $this->assertArrayHasKey('duration_ms', $finishContext);
    }

    public function testFinishRecordMarksErrorStatusWhenOperationThrows(): void
    {
        $logger = new TraceLogger();
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
        $logger = new TraceLogger();
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
        $logger = new TraceLogger();
        $tracer = new RunTracer($logger);

        $tracer->inSpan('root.op', ['run_id' => 'run-1'], static fn (): string => 'ok', root: true);

        $startRecord = $logger->records[0];
        $this->assertNull($startRecord['context']['parent_span_id']);
    }
}

final class TraceLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        unset($level);

        $this->records[] = [
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
