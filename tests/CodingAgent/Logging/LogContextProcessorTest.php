<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\CodingAgent\Logging\LogContextProcessor;
use Ineersa\CodingAgent\Logging\TelemetrySessionKeyProvider;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LogContextProcessorTest extends TestCase
{
    private LogContextProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new LogContextProcessor(new TelemetrySessionKeyProvider(__DIR__));
    }

    protected function tearDown(): void
    {
    }

    public function testEmptyContextInjectsProcessMemoryOnly(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'test',
        );

        $result = ($this->processor)($record);

        // Ambient context fields (run_id, component) should NOT appear.
        $this->assertArrayNotHasKey('run_id', $result->extra);
        $this->assertArrayNotHasKey('component', $result->extra);

        $this->assertSame(getmypid(), $result->extra['pid']);
        $this->assertSame(getmypid(), $result->extra['sampler_pid']);
        $this->assertIsInt($result->extra['memory_usage']);
        $this->assertIsInt($result->extra['memory_allocated']);
        $this->assertGreaterThanOrEqual($result->extra['memory_usage'], $result->extra['memory_allocated']);
        $this->assertIsInt($result->extra['memory_peak']);
        $this->assertIsString($result->extra['memory_limit']);

        // Verify nothing beyond the process samples leaked in.
        $allowedKeys = ['pid', 'sampler_pid', 'memory_usage', 'memory_allocated', 'memory_peak', 'memory_limit'];
        foreach ($result->extra as $key => $value) {
            $this->assertContains($key, $allowedKeys, "Unexpected extra key: \"{$key}\"");
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

        $this->assertSame('run-1', $result->extra['run_id']);
        $this->assertSame('runtime', $result->extra['component']);

        RunLogContext::leave();
    }

    public function testSessionKeyFollowsEffectiveSessionIdWithoutChangingRawIds(): void
    {
        $record = new LogRecord(new \DateTimeImmutable(), 'test', Level::Info, 'test', context: ['session_id' => 75]);
        $key = hash('sha256', (string) realpath(__DIR__)).':75';
        $this->assertSame($key, ($this->processor)($record)->extra['session_key']);
        $this->assertSame(75, ($this->processor)($record)->context['session_id']);

        RunLogContext::enter(['session_id' => 'ambient', 'run_id' => 'run-unchanged']);
        try {
            $result = ($this->processor)($record);
            $this->assertSame($key, $result->extra['session_key']);
            $this->assertSame('run-unchanged', $result->extra['run_id']);
            $extra = ($this->processor)($record->with(extra: ['session_id' => 'extra']));
            $this->assertSame(hash('sha256', (string) realpath(__DIR__)).':extra', $extra->extra['session_key']);
            $this->assertSame('extra', $extra->extra['session_id']);
            $this->assertArrayNotHasKey('session_key', ($this->processor)($record->with(context: ['session_id' => null]))->extra);
            $this->assertArrayNotHasKey('session_key', ($this->processor)($record->with(extra: ['session_id' => null]))->extra);
            $ambient = ($this->processor)($record->with(context: []));
            $this->assertSame(hash('sha256', (string) realpath(__DIR__)).':ambient', $ambient->extra['session_key']);
        } finally {
            RunLogContext::leave();
        }
    }

    public function testSessionKeySeparatesProjectsAndResolvesSymlinkAliases(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('telemetry-session-key');
        $filesystem = new Filesystem();
        try {
            $filesystem->mkdir([$directory.'/first', $directory.'/second']);
            $filesystem->symlink($directory.'/first', $directory.'/alias');
            $record = new LogRecord(new \DateTimeImmutable(), 'test', Level::Info, 'test', context: ['session_id' => '75']);
            $first = new LogContextProcessor(new TelemetrySessionKeyProvider($directory.'/first'));
            $second = new LogContextProcessor(new TelemetrySessionKeyProvider($directory.'/second'));
            $alias = new LogContextProcessor(new TelemetrySessionKeyProvider($directory.'/alias'));
            $this->assertSame(hash('sha256', (string) realpath($directory.'/first')).':75', $first($record)->extra['session_key']);
            $this->assertNotSame($first($record)->extra['session_key'], $second($record)->extra['session_key']);
            $this->assertSame($first($record)->extra['session_key'], $alias($record)->extra['session_key']);
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testSamplerPidRemainsCallerWhenAmbientPidDiffers(): void
    {
        RunLogContext::enter(['pid' => 424242, 'run_id' => 'run-worker']);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'process.memory.checkpoint',
        );

        $result = ($this->processor)($record);

        // Baseline injects actual process pid before ambient merge, so ambient
        // pid alone cannot override it. sampler_pid remains the caller process.
        $this->assertSame(getmypid(), $result->extra['sampler_pid']);
        $this->assertSame(getmypid(), $result->extra['pid']);
        $this->assertSame('run-worker', $result->extra['run_id']);

        RunLogContext::leave();
    }

    public function testExplicitCallSitePidOverridesProcessSampleButNotSamplerPid(): void
    {
        RunLogContext::enter(['pid' => 424242, 'run_id' => 'run-worker']);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'process.memory.checkpoint',
            context: ['pid' => 999001],
        );

        $result = ($this->processor)($record);

        $this->assertSame(getmypid(), $result->extra['sampler_pid']);
        $this->assertArrayNotHasKey('pid', $result->extra);
        $this->assertSame(999001, $result->context['pid']);
        $this->assertSame('run-worker', $result->extra['run_id']);

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
        $this->assertSame('overridden', $result->extra['run_id']);
        // ambient component should still be injected
        $this->assertSame('runtime', $result->extra['component']);

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
        $this->assertArrayNotHasKey('event_type', $result->extra,
            'Ambient event_type must not leak into extra when log context provides it');
        // component should still be injected since it's not in context
        $this->assertSame('runtime', $result->extra['component']);

        // The original context should be preserved
        $this->assertSame('completed', $result->context['event_type']);

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
        $this->assertArrayNotHasKey('component', $result->extra,
            'Ambient component must not leak into extra when log context provides it');
        $this->assertArrayNotHasKey('event_type', $result->extra,
            'Ambient event_type must not leak into extra when log context provides it');

        // Original context preserved
        $this->assertSame('storage', $result->context['component']);
        $this->assertSame('event_store.appended', $result->context['event_type']);

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
        $this->assertArrayNotHasKey('run_id', $result->extra);
        // empty string key should be skipped
        $this->assertArrayNotHasKey('', $result->extra);
        // component is valid and should be injected
        $this->assertSame('runtime', $result->extra['component']);

        RunLogContext::leave();
    }
}
