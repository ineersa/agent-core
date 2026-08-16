<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
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
