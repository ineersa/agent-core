<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Controller\ConsumerStdoutPoller;
use Ineersa\CodingAgent\Runtime\Controller\ConsumerStdoutSourceInterface;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
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

    public function testCoalescesMatchingStreamChunksBeforeCompletionAndControl(): void
    {
        $source = new FakeConsumerStdoutSource([
            'llm#0' => $this->line('assistant.text_delta', ['block_id' => 'text-1', 'text' => 'Hello '])
                .$this->line('assistant.text_delta', ['block_id' => 'text-1', 'text' => 'world'])
                .$this->line('assistant.text_completed', ['block_id' => 'text-1'])
                .$this->line('human_input.requested', ['question_id' => 'q-1']),
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

        $events = $this->eventsFromStdout($emitter);
        $this->assertSame(['assistant.text_delta', 'assistant.text_completed', 'human_input.requested'], array_map(
            static fn (RuntimeEvent $event): string => $event->type,
            $events,
        ));
        $this->assertSame('Hello world', $events[0]->payload['text']);
    }

    public function testForwardsCanonicalToolProgressInsteadOfDroppingItAsTransientBacklog(): void
    {
        $source = new FakeConsumerStdoutSource([
            'run_control#0' => $this->line('tool_execution.output_delta', [
                'tool_call_id' => 'tc-parallel',
                'subagent_progress' => [
                    'mode' => 'parallel',
                    'status' => 'completed',
                    'completed_count' => 2,
                    'total_count' => 2,
                ],
            ], seq: 53),
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

        $events = $this->eventsFromStdout($emitter);
        $this->assertCount(1, $events);
        $this->assertSame(53, $events[0]->seq);
        $this->assertSame('completed', $events[0]->payload['subagent_progress']['status'] ?? null);
        $this->assertSame(2, $events[0]->payload['subagent_progress']['completed_count'] ?? null);
    }

    public function testDoesNotReorderDistinctStreamKeysOrRetainFramesBetweenPolls(): void
    {
        $source = new FakeConsumerStdoutSource([
            'llm#0' => $this->line('assistant.text_delta', ['block_id' => 'text-1', 'text' => 'a'])
                .$this->line('assistant.text_delta', ['block_id' => 'text-2', 'text' => 'b'])
                .$this->line('assistant.text_delta', ['block_id' => 'text-1', 'text' => 'c']),
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
            'llm#0' => $this->line('assistant.text_delta', ['block_id' => 'text-1', 'text' => 'd']),
        ];
        $poller->pollOnce();

        $events = $this->eventsFromStdout($emitter);
        $this->assertSame(['a', 'b', 'c', 'd'], array_map(
            static fn (RuntimeEvent $event): string => $event->payload['text'],
            $events,
        ));
    }

    /** @param array<string, mixed> $payload */
    private function line(string $type, array $payload, int $seq = 0): string
    {
        return JsonlCodec::encodeEvent(new RuntimeEvent($type, 'run-1', $seq, $payload));
    }

    /** @return list<RuntimeEvent> */
    private function eventsFromStdout(RuntimeEventEmitter $emitter): array
    {
        return array_map(
            static fn (string $line): RuntimeEvent => JsonlCodec::decodeEvent($line),
            array_filter(explode("\n", $this->readStdout($emitter))),
        );
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
