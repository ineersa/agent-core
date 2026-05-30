<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Contract\SpanProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

final class RunTracerTest extends TestCase
{
    public function testNoSpanProviderStillWorks(): void
    {
        $logger = new TraceLogger();
        $tracer = new RunTracer($logger);

        $result = $tracer->inSpan('test.noop', [], fn (): string => 'hello');

        self::assertSame('hello', $result);

        $records = $logger->records;
        self::assertCount(2, $records);
        self::assertSame('agent_loop.trace.start', $records[0]['message']);
        self::assertSame('agent_loop.trace.finish', $records[1]['message']);
    }

    public function testSpanProviderIsCalledCorrectly(): void
    {
        $logger = new TraceLogger();
        $provider = new FakeSpanProvider();
        $tracer = new RunTracer($logger, $provider);

        $result = $tracer->inSpan('test.operation', [
            'run_id' => 'run-1',
            'turn_no' => 3,
        ], fn (): string => 'result');

        self::assertSame('result', $result);

        // Verify provider was called
        self::assertCount(1, $provider->started);
        self::assertCount(1, $provider->closed);
        self::assertSame('test.operation', $provider->started[0]['name']);
        self::assertSame('run-1', $provider->started[0]['tags']['run_id']);
        self::assertSame(3, $provider->started[0]['tags']['turn_no']);

        // Close tags include duration, status, outcome
        $closeTags = $provider->closed[0]['tags'];
        self::assertArrayHasKey('duration_ms', $closeTags);
        self::assertSame('ok', $closeTags['status']);
        self::assertSame('success', $closeTags['outcome']);
    }

    public function testSpanProviderOnError(): void
    {
        $logger = new TraceLogger();
        $provider = new FakeSpanProvider();
        $tracer = new RunTracer($logger, $provider);

        $this->expectException(\RuntimeException::class);

        try {
            $tracer->inSpan('test.error', ['run_id' => 'run-1'], function (): never {
                throw new \RuntimeException('test error');
            });
        } finally {
            // Provider should have both start and close
            self::assertCount(1, $provider->started);
            self::assertCount(1, $provider->closed);

            $closeTags = $provider->closed[0]['tags'];
            self::assertSame('error', $closeTags['status']);
            self::assertSame('error', $closeTags['outcome']);
        }
    }

    public function testSpanProviderNestedSpans(): void
    {
        $logger = new TraceLogger();
        $provider = new FakeSpanProvider();
        $tracer = new RunTracer($logger, $provider);

        $result = $tracer->inSpan('parent', ['run_id' => 'run-1'], function () use ($tracer): string {
            $inner = $tracer->inSpan('child', ['step_id' => 'step-1'], fn (): string => 'done');

            return 'parent-'.$inner;
        });

        self::assertSame('parent-done', $result);

        // Two startSpan and two closeSpan calls
        self::assertCount(2, $provider->started);
        self::assertCount(2, $provider->closed);

        // Parent started first, child started second,
        // child closed first (LIFO), parent closed second
        self::assertSame('parent', $provider->started[0]['name']);
        self::assertSame('child', $provider->started[1]['name']);
    }

    public function testSpanProviderRootSpanDoesNotSetParent(): void
    {
        $logger = new TraceLogger();
        $provider = new FakeSpanProvider();
        $tracer = new RunTracer($logger, $provider);

        $tracer->inSpan('root.op', ['run_id' => 'run-1'], fn (): string => 'ok', root: true);

        $startRecord = $logger->records[0];
        self::assertNull($startRecord['context']['parent_span_id']);
    }
}

final class TraceLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, Stringable|string $message, array $context = []): void
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
