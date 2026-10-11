<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\CommittedRuntimeEventStdoutSink;
use Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Process\Process;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Stream\CommittedRuntimeEventStdoutSink
 */
final class CommittedRuntimeEventStdoutSinkTest extends IsolatedKernelTestCase
{
    public function testEmitNoopsWhenStdoutIsNotPipe(): void
    {
        $logger = new TestLogger();
        $sink = new CommittedRuntimeEventStdoutSink($logger, new StdoutRuntimeEventSink(), self::getContainer()->get(\Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapEmissionGate::class));

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
            require $argv[1].'/vendor/autoload.php';
            $kernel = new \Ineersa\CodingAgent\Kernel('test', false);
            $kernel->boot();

            $sink = new \Ineersa\CodingAgent\Runtime\Stream\CommittedRuntimeEventStdoutSink(new \Psr\Log\NullLogger(), new \Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink(), $kernel->getContainer()->get('test.service_container')->get(\Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapEmissionGate::class));
            $sink->emit(new \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent(
                type: \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum::TurnStarted->value,
                runId: 'run-a',
                seq: 3,
                payload: ['url' => 'https://example.com/path/to', 'text' => 'héllo'],
            ));
            PHP, \dirname(__DIR__, 4)], getcwd(), ['HATFIELD_SESSION_ID' => false]);
        $process->mustRun();

        $output = $process->getOutput();
        $this->assertSame(JsonlCodec::encodeEvent($event), $output);
        $this->assertStringContainsString('https://example.com/path/to', $output);
        $this->assertSame(1, substr_count($output, "\n"));
    }
}
