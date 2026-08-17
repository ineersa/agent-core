<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeTransportException;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink;
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
