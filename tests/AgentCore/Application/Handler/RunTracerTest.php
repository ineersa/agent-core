<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Contract\SpanProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class RunTracerTest extends TestCase
{
    public function testNoSpanProviderStillWorks(): void
    {
        $logger = new TraceLogger();
        $tracer = new RunTracer($logger);

        $result = $tracer->inSpan('test.noop', [], static fn (): string => 'hello');

        $this->assertSame('hello', $result);

        $records = $logger->records;
        $this->assertCount(2, $records);
        $this->assertSame('agent_loop.trace.start', $records[0]['message']);
        $this->assertSame('agent_loop.trace.finish', $records[1]['message']);
    }

    public function testSpanProviderIsCalledCorrectly(): void
    {
        $logger = new TraceLogger();
        $provider = new FakeSpanProvider();
        $tracer = new RunTracer($logger, $provider);

        $result = $tracer->inSpan('test.operation', [
            'run_id' => 'run-1',
            'turn_no' => 3,
        ], static fn (): string => 'result');

        $this->assertSame('result', $result);

        // Verify provider was called
        $this->assertCount(1, $provider->started);
        $this->assertCount(1, $provider->closed);
        $this->assertSame('test.operation', $provider->started[0]['name']);
        $this->assertSame('run-1', $provider->started[0]['tags']['run_id']);
        $this->assertSame(3, $provider->started[0]['tags']['turn_no']);

        // Close tags include duration, status, outcome
        $closeTags = $provider->closed[0]['tags'];
        $this->assertArrayHasKey('duration_ms', $closeTags);
        $this->assertSame('ok', $closeTags['status']);
        $this->assertSame('success', $closeTags['outcome']);
    }

    public function testSpanProviderOnError(): void
    {
        $logger = new TraceLogger();
        $provider = new FakeSpanProvider();
        $tracer = new RunTracer($logger, $provider);

        $this->expectException(\RuntimeException::class);

        try {
            $tracer->inSpan('test.error', ['run_id' => 'run-1'], static function (): never {
                throw new \RuntimeException('test error');
            });
        } finally {
            // Provider should have both start and close
            $this->assertCount(1, $provider->started);
            $this->assertCount(1, $provider->closed);

            $closeTags = $provider->closed[0]['tags'];
            $this->assertSame('error', $closeTags['status']);
            $this->assertSame('error', $closeTags['outcome']);
        }
    }

    public function testSpanProviderNestedSpans(): void
    {
        $logger = new TraceLogger();
        $provider = new FakeSpanProvider();
        $tracer = new RunTracer($logger, $provider);

        $result = $tracer->inSpan('parent', ['run_id' => 'run-1'], static function () use ($tracer): string {
            $inner = $tracer->inSpan('child', ['step_id' => 'step-1'], static fn (): string => 'done');

            return 'parent-'.$inner;
        });

        $this->assertSame('parent-done', $result);

        // Two startSpan and two closeSpan calls
        $this->assertCount(2, $provider->started);
        $this->assertCount(2, $provider->closed);

        // Parent started first, child started second,
        // child closed first (LIFO), parent closed second
        $this->assertSame('parent', $provider->started[0]['name']);
        $this->assertSame('child', $provider->started[1]['name']);
    }

    public function testSpanProviderRootSpanDoesNotSetParent(): void
    {
        $logger = new TraceLogger();
        $provider = new FakeSpanProvider();
        $tracer = new RunTracer($logger, $provider);

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

final class FakeSpanProvider implements SpanProviderInterface
{
    /** @var list<array{name: string, tags: array<string, mixed>, id: string}> */
    public array $started = [];

    /** @var list<array{id: string, tags: array<string, mixed>}> */
    public array $closed = [];

    private int $nextId = 1;

    public function startSpan(string $operationName, array $tags = []): ?string
    {
        $id = 'fake-span-'.($this->nextId++);
        $this->started[] = ['name' => $operationName, 'tags' => $tags, 'id' => $id];

        return $id;
    }

    public function closeSpan(?string $spanId, array $tags = []): void
    {
        if (null === $spanId) {
            return;
        }

        $this->closed[] = ['id' => $spanId, 'tags' => $tags];
    }

    public function currentContext(): array
    {
        return [];
    }
}
