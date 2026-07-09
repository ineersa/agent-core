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
        $inner = new RecordingEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $mapper = new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher()));

        $store = new StreamingCommittedRuntimeEventStore($inner, $mapper, $sink, true);
        $store->append(new RunEvent('run-a', 5, 0, RunEventTypeEnum::RunStarted->value, []));

        $this->assertCount(1, $inner->appended);
        $this->assertCount(1, $sink->emitted);
        $this->assertSame(RuntimeEventTypeEnum::RunStarted->value, $sink->emitted[0]->type);
        $this->assertSame(5, $sink->emitted[0]->seq);
    }

    public function testAppendManyEmitsInOrderAfterBatchAppend(): void
    {
        $inner = new RecordingEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $mapper = new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher()));

        $store = new StreamingCommittedRuntimeEventStore($inner, $mapper, $sink, true);
        $store->appendMany([
            new RunEvent('run-a', 1, 0, RunEventTypeEnum::RunStarted->value, []),
            new RunEvent('run-a', 2, 0, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
        ]);

        $this->assertSame([1, 2], array_map(static fn (RuntimeEvent $e): int => $e->seq, $sink->emitted));
    }

    public function testAppendWithNextSeqEmitsMappedRuntimeEventAfterInnerAppend(): void
    {
        $inner = new RecordingSequencedEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $mapper = new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher()));

        $store = new StreamingCommittedRuntimeEventStore($inner, $mapper, $sink, true);
        $persisted = $store->appendWithNextSeq(new RunEvent('run-a', 0, 0, RunEventTypeEnum::ToolExecutionUpdate->value, ['tool_name' => 'subagent']));

        $this->assertSame(7, $persisted->seq);
        $this->assertCount(1, $inner->sequencedAppended);
        $this->assertCount(1, $sink->emitted);
        $this->assertSame(RuntimeEventTypeEnum::ToolExecutionOutputDelta->value, $sink->emitted[0]->type);
        $this->assertSame(7, $sink->emitted[0]->seq);
    }

    public function testStreamingDisabledSkipsStdoutEmit(): void
    {
        $inner = new RecordingEventStore();
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
final class RecordingSequencedEventStore implements \Ineersa\AgentCore\Contract\SequencedEventStoreInterface
{
    /** @var list<RunEvent> */
    public array $sequencedAppended = [];

    public function append(RunEvent $event): void
    {
        $this->sequencedAppended[] = $event;
    }

    public function appendMany(array $events): void
    {
        foreach ($events as $event) {
            $this->append($event);
        }
    }

    public function appendWithNextSeq(RunEvent $event): RunEvent
    {
        $persisted = new RunEvent($event->runId, 7, $event->turnNo, $event->type, $event->payload);
        $this->sequencedAppended[] = $persisted;

        return $persisted;
    }

    public function appendManyWithNextSeq(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            $out[] = $this->appendWithNextSeq($event);
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
final class RecordingEventStore implements EventStoreInterface
{
    /** @var list<RunEvent> */
    public array $appended = [];

    public function append(RunEvent $event): void
    {
        $this->appended[] = $event;
    }

    public function appendMany(array $events): void
    {
        foreach ($events as $event) {
            $this->append($event);
        }
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
