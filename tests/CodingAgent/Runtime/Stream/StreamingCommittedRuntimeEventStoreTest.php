<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\StreamingCommittedRuntimeEventStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Stream\StreamingCommittedRuntimeEventStore
 */
final class StreamingCommittedRuntimeEventStoreTest extends TestCase
{
    public function testAppendEmitsMappedRuntimeEventAfterInnerAppend(): void
    {
        $inner = new RecordingCommittedEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $mapper = new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher()));

        $store = new StreamingCommittedRuntimeEventStore($inner, $mapper, $sink, true);
        $store->append(new RunEvent('run-a', 5, 0, RunEventTypeEnum::RunStarted->value, []));

        $this->assertCount(1, $inner->appended);
        $this->assertCount(1, $sink->emitted);
        $this->assertSame(RuntimeEventTypeEnum::RunStarted->value, $sink->emitted[0]->type);
        $this->assertSame(5, $sink->emitted[0]->seq);
    }

    public function testAppendChildRunEventEmitsRuntimeEventPreservingChildRunId(): void
    {
        $childRunId = 'child-subagent-run-7f3a';
        $inner = new RecordingCommittedEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $mapper = new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher()));

        $store = new StreamingCommittedRuntimeEventStore($inner, $mapper, $sink, true);
        $store->append(new RunEvent($childRunId, 3, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]));

        $this->assertCount(1, $sink->emitted);
        $this->assertSame($childRunId, $sink->emitted[0]->runId);
        $this->assertSame(3, $sink->emitted[0]->seq);
        $this->assertSame($childRunId, $inner->appended[0]->runId);
    }

    public function testAppendManyEmitsInOrderAfterBatchAppend(): void
    {
        $inner = new RecordingCommittedEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $mapper = new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher()));

        $store = new StreamingCommittedRuntimeEventStore($inner, $mapper, $sink, true);
        $store->appendMany([
            new RunEvent('run-a', 1, 0, RunEventTypeEnum::RunStarted->value, []),
            new RunEvent('run-a', 2, 0, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
        ]);

        $this->assertSame([1, 2], array_map(static fn (RuntimeEvent $e): int => $e->seq, $sink->emitted));
    }

    public function testStreamingDisabledSkipsStdoutEmit(): void
    {
        $inner = new RecordingCommittedEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $mapper = new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher()));

        $store = new StreamingCommittedRuntimeEventStore($inner, $mapper, $sink, false);
        $store->append(new RunEvent('run-a', 1, 0, RunEventTypeEnum::RunStarted->value, []));

        $this->assertCount(1, $inner->appended);
        $this->assertCount(0, $sink->emitted);
    }
}

/**
 * @internal
 */
final class RecordingCommittedEventStore implements EventStoreInterface
{
    /** @var list<RunEvent> */
    public array $appended = [];

    public function append(RunEvent $event): RunEvent
    {
        $persisted = new RunEvent($event->runId, $event->seq > 0 ? $event->seq : 1, $event->turnNo, $event->type, $event->payload, $event->createdAt);
        $this->appended[] = $persisted;

        return $persisted;
    }

    public function appendMany(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            $out[] = $this->append($event);
        }

        return $out;
    }

    public function allFor(string $runId): array
    {
        return [];
    }
}

/**
 * @internal
 */
final class RecordingCommittedStdoutSink implements RuntimeEventSinkInterface
{
    /** @var list<RuntimeEvent> */
    public array $emitted = [];

    public function emit(RuntimeEvent $event): void
    {
        $this->emitted[] = $event;
    }
}
