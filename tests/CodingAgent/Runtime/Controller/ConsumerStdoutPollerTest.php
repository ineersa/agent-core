<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Controller\ConsumerStdoutPoller;
use Ineersa\CodingAgent\Runtime\Controller\ConsumerStdoutSourceInterface;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Controller\ConsumerStdoutPoller
 */
final class ConsumerStdoutPollerTest extends TestCase
{
    public function testPollsMultipleConsumersAndAccumulatesPartialLines(): void
    {
        $source = new FakeConsumerStdoutSource([
            'llm#0' => '{"v":1,"type":"assistant.text.delta","runId":"r1","seq":0,"payload":{}}'."\n",
            'tool#0' => '{"v":1,"type":"turn.started","runId":"r1","seq":10,"pay',
            'tool#1' => 'not-json noise from messenger'."\n",
        ]);

        $emitter = $this->createEmitter();
        $emitter->openStdout();
        $this->replaceStdoutWithMemory($emitter);

        $poller = new ConsumerStdoutPoller(
            $source,
            $emitter,
            new RuntimeExceptionBoundary(new EventDispatcher()),
            $this->createStub(LoggerInterface::class),
        );

        $poller->pollOnce();
        $source->chunks = [
            'tool#0' => 'load":{}}'."\n",
        ];
        $poller->pollOnce();

        $raw = $this->readStdout($emitter);
        $this->assertStringContainsString('assistant.text.delta', $raw);
        $this->assertStringContainsString('turn.started', $raw);
        $this->assertStringNotContainsString('not-json', $raw);
    }

    private function createEmitter(): RuntimeEventEmitter
    {
        return new RuntimeEventEmitter(
            eventClient: null,
            boundary: new RuntimeExceptionBoundary(new EventDispatcher()),
            logger: $this->createStub(LoggerInterface::class),
        );
    }

    private function replaceStdoutWithMemory(RuntimeEventEmitter $emitter): void
    {
        $ref = new \ReflectionClass($emitter);
        $prop = $ref->getProperty('stdout');
        $memory = fopen('php://memory', 'w+b');
        $this->assertIsResource($memory);
        $prop->setValue($emitter, $memory);
    }

    private function readStdout(RuntimeEventEmitter $emitter): string
    {
        $ref = new \ReflectionClass($emitter);
        $prop = $ref->getProperty('stdout');
        $stdout = $prop->getValue($emitter);
        $this->assertIsResource($stdout);
        rewind($stdout);

        return stream_get_contents($stdout) ?: '';
    }
}

/**
 * @internal
 */
final class FakeConsumerStdoutSource implements ConsumerStdoutSourceInterface
{
    /** @param array<string, string> $chunks */
    public function __construct(public array $chunks)
    {
    }

    public function readIncrementalStdoutByConsumer(): iterable
    {
        foreach ($this->chunks as $key => $chunk) {
            yield $key => $chunk;
        }
        $this->chunks = [];
    }
}
