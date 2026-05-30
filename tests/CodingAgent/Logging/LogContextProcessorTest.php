<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\CodingAgent\Logging\LogContextProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class LogContextProcessorTest extends TestCase
{
    private LogContextProcessor $processor;

    protected function setUp(): void
    {
        RunLogContext::reset();
        $this->processor = new LogContextProcessor();
    }

    protected function tearDown(): void
    {
        RunLogContext::reset();
    }

    public function testEmptyContextOnlyInjectsDdTraceIds(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'test',
        );

        $result = ($this->processor)($record);

        // When ddtrace is loaded (as in this env), trace IDs are injected.
        // Ambient context fields (run_id, component) should NOT appear.
        self::assertArrayNotHasKey('run_id', $result->extra);
        self::assertArrayNotHasKey('component', $result->extra);

        // dd.trace_id and dd.span_id may or may not be present
        // depending on whether ddtrace is loaded. We just verify
        // nothing else leaked in.
        $allowedKeys = ['dd.trace_id', 'dd.span_id'];
        foreach ($result->extra as $key => $value) {
            self::assertContains($key, $allowedKeys, "Unexpected extra key: \"{$key}\"");
        }
    }

    public function testInjectsAmbientContextIntoExtra(): void
    {
        RunLogContext::enter(['run_id' => 'run-1', 'component' => 'runtime']);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'test',
        );

        $result = ($this->processor)($record);

        self::assertSame('run-1', $result->extra['run_id']);
        self::assertSame('runtime', $result->extra['component']);

        RunLogContext::leave();
    }

    public function testDoesNotOverwriteExistingExtra(): void
    {
        RunLogContext::enter(['run_id' => 'run-1', 'component' => 'runtime']);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'test',
            extra: ['run_id' => 'overridden'],
        );

        $result = ($this->processor)($record);

        // Ambient run_id should not overwrite the explicitly-set extra value
        self::assertSame('overridden', $result->extra['run_id']);
        // ambient component should still be injected
        self::assertSame('runtime', $result->extra['component']);

        RunLogContext::leave();
    }

    public function testDoesNotInjectAmbientFieldWhenCallSiteContextHasSameKey(): void
    {
        // Ambient context has event_type='started' and component='runtime'
        RunLogContext::enter(['event_type' => 'started', 'component' => 'runtime']);

        // But log call explicitly provides event_type='completed' in context
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'llm.request.completed',
            context: ['event_type' => 'completed'],
        );

        $result = ($this->processor)($record);

        // event_type should NOT appear in extra (it's in context and caller controls it)
        self::assertArrayNotHasKey('event_type', $result->extra,
            'Ambient event_type must not leak into extra when log context provides it');
        // component should still be injected since it's not in context
        self::assertSame('runtime', $result->extra['component']);

        // The original context should be preserved
        self::assertSame('completed', $result->context['event_type']);

        RunLogContext::leave();
    }

    public function testComponentConflictBetweenAmbientAndCallSiteContext(): void
    {
        // Ambient scope has component='llm'
        RunLogContext::enter(['component' => 'llm', 'event_type' => 'llm.request.started']);

        // Log call provides component='storage' in context
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'event_store.appended',
            context: ['component' => 'storage', 'event_type' => 'event_store.appended'],
        );

        $result = ($this->processor)($record);

        // Ambient component must not leak into extra since context provides it
        self::assertArrayNotHasKey('component', $result->extra,
            'Ambient component must not leak into extra when log context provides it');
        self::assertArrayNotHasKey('event_type', $result->extra,
            'Ambient event_type must not leak into extra when log context provides it');

        // Original context preserved
        self::assertSame('storage', $result->context['component']);
        self::assertSame('event_store.appended', $result->context['event_type']);

        RunLogContext::leave();
    }

    public function testNullAndEmptyKeysAreSkipped(): void
    {
        RunLogContext::enter(['run_id' => null, 'component' => 'runtime', '' => 'empty-key']);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'test',
        );

        $result = ($this->processor)($record);

        // run_id is null so should not be injected
        self::assertArrayNotHasKey('run_id', $result->extra);
        // empty string key should be skipped
        self::assertArrayNotHasKey('', $result->extra);
        // component is valid and should be injected
        self::assertSame('runtime', $result->extra['component']);

        RunLogContext::leave();
    }
}
