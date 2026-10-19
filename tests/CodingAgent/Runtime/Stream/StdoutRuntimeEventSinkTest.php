<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeTransportException;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink
 */
final class StdoutRuntimeEventSinkTest extends TestCase
{
    /**
     * Proves the wire bytes: emitting into a real stdout pipe (subprocess) produces exactly
     * JsonlCodec::encodeEvent() — slash-sensitive payload unescaped, exactly one newline.
     */
    public function testEmitWritesCodecEncodedLineToStdoutPipe(): void
    {
        $event = $this->slashSensitiveEvent();

        $output = $this->runInSubprocess(
            <<<'PHP'
            require getcwd().'/vendor/autoload.php';

            $sink = new \Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink();
            $sink->emit(new \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent(
                type: \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum::TurnStarted->value,
                runId: 'run-a',
                seq: 3,
                payload: ['url' => 'https://example.com/path/to', 'text' => 'héllo'],
            ));
            PHP
        );

        $this->assertSame(JsonlCodec::encodeEvent($event), $output);
        $this->assertStringContainsString('https://example.com/path/to', $output);
        $this->assertSame(1, substr_count($output, "\n"));
    }

    public function testEmitThrowsTypedTransportExceptionOnWriteFailure(): void
    {
        // Force the sink to believe STDOUT is a pipe and point it at a
        // read-only handle so fwrite fails — the producer boundary must
        // surface a typed RuntimeTransportException (fatal for the poller).
        $readOnly = fopen('php://memory', 'rb');
        $this->assertNotFalse($readOnly);

        $isPipe = new \ReflectionProperty(StdoutRuntimeEventSink::class, 'isPipe');
        $stdout = new \ReflectionProperty(StdoutRuntimeEventSink::class, 'stdout');
        $isPipe->setValue(null, true);
        $stdout->setValue(null, $readOnly);

        try {
            $this->expectException(RuntimeTransportException::class);
            $sink = new StdoutRuntimeEventSink();
            $sink->emit($this->slashSensitiveEvent());
        } finally {
            $stdout->setValue(null, null);
            $isPipe->setValue(null, null);
            fclose($readOnly);
        }
    }

    /** @return iterable<string, array{string|Level, bool}> */
    public static function loggingLevels(): iterable
    {
        yield 'info string' => ['info', false];
        yield 'info enum' => [Level::Info, false];
        yield 'warning' => [Level::Warning, false];
        yield 'debug string' => ['debug', true];
        yield 'debug enum' => [Level::Debug, true];
    }

    #[DataProvider('loggingLevels')]
    public function testDetailsRespectConfiguredLevelWithoutChangingOuterSpansOrBytes(string|Level $level, bool $debug): void
    {
        $logger = new TestLogger();
        $tracer = new RunTracer($logger);
        $sink = new StdoutRuntimeEventSink($tracer, new LoggingConfig(level: $level));
        $event = $this->slashSensitiveEvent();

        $this->withPipe(static function ($stream) use ($logger, $tracer, $sink, $event, $debug): void {
            $tracer->inSpan('publication', [], static function () use ($tracer, $sink, $event): void {
                self::assertTrue($sink->write($event));
                self::assertSame('returned', $tracer->inSpan('next', [], static fn (): string => 'returned'));
            });

            rewind($stream);
            self::assertSame(JsonlCodec::encodeEvent($event), stream_get_contents($stream));
            self::assertSame($debug ? ['info', 'debug', 'debug', 'debug', 'debug', 'info', 'info', 'info'] : ['info', 'info', 'info', 'info'], array_column($logger->records, 'level'));
            // A skipped diagnostic must not allocate a span ID or change nesting.
            $next = $logger->records[$debug ? 5 : 1]['context'];
            self::assertSame($debug ? 'span-4' : 'span-2', $next['span_id']);
            self::assertSame('span-1', $next['parent_span_id']);
            if ($debug) {
                foreach ([1 => 'encode', 3 => 'write'] as $index => $phase) {
                    self::assertSame('latency_probe.stdout.'.$phase, $logger->records[$index]['context']['span_name']);
                    self::assertSame('span-1', $logger->records[$index]['context']['parent_span_id']);
                    self::assertSame('ok', $logger->records[$index + 1]['context']['status']);
                    self::assertArrayHasKey('duration_ms', $logger->records[$index + 1]['context']);
                }
            }
        });
    }

    #[DataProvider('loggingLevels')]
    public function testEncodingFailurePropagatesAtEitherLoggingLevel(string|Level $level, bool $debug): void
    {
        $logger = new TestLogger();
        $tracer = new RunTracer($logger);
        $sink = new StdoutRuntimeEventSink($tracer, new LoggingConfig(level: $level));
        $event = new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, 'run-a', 3, ['invalid' => "\xFF"]);

        $this->withPipe(static function ($stream) use ($logger, $tracer, $sink, $event, $debug): void {
            try {
                $tracer->inSpan('publication', [], static fn () => $sink->write($event));
                self::fail('Encoding must fail without replacing malformed bytes.');
            } catch (\JsonException $exception) {
                self::assertSame(\JSON_ERROR_UTF8, $exception->getCode());
            }

            rewind($stream);
            self::assertSame('', stream_get_contents($stream));
            self::assertCount($debug ? 4 : 2, $logger->records);
            if ($debug) {
                self::assertSame('debug', $logger->records[2]['level']);
                self::assertSame('error', $logger->records[2]['context']['status']);
                self::assertSame('latency_probe.stdout.encode', $logger->records[2]['context']['span_name']);
            }
            self::assertSame('error', $logger->records[$debug ? 3 : 1]['context']['status']);
            $tracer->inSpan('after', [], static fn () => null);
            self::assertNull($logger->records[$debug ? 4 : 2]['context']['parent_span_id']);
            self::assertSame($debug ? 'span-3' : 'span-2', $logger->records[$debug ? 4 : 2]['context']['span_id']);
        });
    }

    #[DataProvider('loggingLevels')]
    public function testWriteFailurePreservesFalseReturnAndTypedEmitException(string|Level $level, bool $debug): void
    {
        $logger = new TestLogger();
        $sink = new StdoutRuntimeEventSink(new RunTracer($logger), new LoggingConfig(level: $level));

        $this->withPipe(function () use ($logger, $sink, $debug): void {
            self::assertFalse($sink->write($this->slashSensitiveEvent()));
            try {
                $sink->emit($this->slashSensitiveEvent());
                self::fail('A failed write must produce a typed transport exception.');
            } catch (RuntimeTransportException $exception) {
                self::assertStringContainsString('fwrite to STDOUT pipe failed', $exception->getMessage());
            }
            self::assertCount($debug ? 8 : 0, $logger->records);
            if ($debug) {
                self::assertSame('debug', $logger->records[3]['level']);
                // A false return is not a thrown operation; preserve tracer status semantics.
                self::assertSame('ok', $logger->records[3]['context']['status']);
            }
        }, 'rb');
    }

    /** @param callable(resource): void $operation */
    private function withPipe(callable $operation, string $mode = 'w+b'): void
    {
        $stream = fopen('php://memory', $mode);
        $this->assertNotFalse($stream);
        $isPipe = new \ReflectionProperty(StdoutRuntimeEventSink::class, 'isPipe');
        $stdout = new \ReflectionProperty(StdoutRuntimeEventSink::class, 'stdout');
        $previousPipe = $isPipe->getValue();
        $previousStdout = $stdout->getValue();
        $isPipe->setValue(null, true);
        $stdout->setValue(null, $stream);
        try {
            $operation($stream);
        } finally {
            $stdout->setValue(null, $previousStdout);
            $isPipe->setValue(null, $previousPipe);
            fclose($stream);
        }
    }

    private function slashSensitiveEvent(): RuntimeEvent
    {
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnStarted->value,
            runId: 'run-a',
            seq: 3,
            payload: ['url' => 'https://example.com/path/to', 'text' => 'héllo'],
        );
    }

    private function runInSubprocess(string $code): string
    {
        $process = new Process([\PHP_BINARY, '-r', $code]);
        $process->setWorkingDirectory(\dirname(__DIR__, 4));
        $process->mustRun();

        return $process->getOutput();
    }
}
