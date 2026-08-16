<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\CommittedRuntimeEventStdoutSink;
use Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Stream\CommittedRuntimeEventStdoutSink
 */
final class CommittedRuntimeEventStdoutSinkTest extends TestCase
{
    public function testEmitNoopsWhenStdoutIsNotPipe(): void
    {
        $logger = new TestLogger();
        $sink = new CommittedRuntimeEventStdoutSink($logger, new StdoutRuntimeEventSink());

        $sink->emit(new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, 'run-a', 3, []));

        $this->assertSame([], $logger->records);
    }

    /**
     * Proves the wire bytes: emitting into a real stdout pipe (subprocess) produces exactly
     * JsonlCodec::encodeEvent() — slash-sensitive payload unescaped, exactly one newline.
     */
    public function testEmitWritesCodecEncodedLineToStdoutPipe(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnStarted->value,
            runId: 'run-a',
            seq: 3,
            payload: ['url' => 'https://example.com/path/to', 'text' => 'héllo'],
        );

        $process = new Process([\PHP_BINARY, '-r', <<<'PHP'
            require getcwd().'/vendor/autoload.php';

            $sink = new \Ineersa\CodingAgent\Runtime\Stream\CommittedRuntimeEventStdoutSink(new \Psr\Log\NullLogger(), new \Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink());
            $sink->emit(new \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent(
                type: \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum::TurnStarted->value,
                runId: 'run-a',
                seq: 3,
                payload: ['url' => 'https://example.com/path/to', 'text' => 'héllo'],
            ));
            PHP]);
        $process->setWorkingDirectory(\dirname(__DIR__, 4));
        $process->mustRun();

        $output = $process->getOutput();
        $this->assertSame(JsonlCodec::encodeEvent($event), $output);
        $this->assertStringContainsString('https://example.com/path/to', $output);
        $this->assertSame(1, substr_count($output, "\n"));
    }
}
