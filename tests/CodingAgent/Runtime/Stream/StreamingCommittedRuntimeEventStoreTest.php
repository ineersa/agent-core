<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
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
    public function testVerifiedFinalizationEmitsHotBatchWithoutArchiveReread(): void
    {
        $inner = new RecordingEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $store = $this->store($inner, $sink, true);

        $store->appendTransition([
            new RunEvent('run-a', 0, 0, RunEventTypeEnum::RunStarted->value, []),
        ], ['run_id' => 'run-a', 'predecessor_seq' => 0]);
        $this->assertCount(0, $sink->emitted);

        $pending = $store->verifiedPendingTransition('run-a');
        $this->assertNotNull($pending);
        $store->finalizeVerifiedTransition('run-a', $pending->identity);

        $this->assertSame(0, $inner->rangeForCalls);
        $this->assertCount(1, $sink->emitted);
        $this->assertSame(RuntimeEventTypeEnum::RunStarted->value, $sink->emitted[0]->type);
        $this->assertSame(1, $sink->emitted[0]->seq);
        $this->assertNull($store->verifiedPendingTransition('run-a'));
    }

    public function testVerifiedFinalizationPreservesChildRunIdFromHotBatch(): void
    {
        $childRunId = 'child-subagent-run-7f3a';
        $inner = new RecordingEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $store = $this->store($inner, $sink, true);

        $store->appendTransition([
            new RunEvent($childRunId, 0, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
        ], ['run_id' => $childRunId, 'predecessor_seq' => 0]);
        $pending = $store->verifiedPendingTransition($childRunId);
        $this->assertNotNull($pending);
        $store->finalizeVerifiedTransition($childRunId, $pending->identity);

        $this->assertSame(0, $inner->rangeForCalls);
        $this->assertSame($childRunId, $sink->emitted[0]->runId);
        $this->assertSame(1, $sink->emitted[0]->seq);
        $this->assertSame($childRunId, $inner->appended[0]->runId);
    }

    public function testVerifiedFinalizationEmitsHotBatchInOrder(): void
    {
        $inner = new RecordingEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $store = $this->store($inner, $sink, true);

        $store->appendTransition([
            new RunEvent('run-a', 0, 0, RunEventTypeEnum::RunStarted->value, []),
            new RunEvent('run-a', 0, 0, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
        ], ['run_id' => 'run-a', 'predecessor_seq' => 0]);
        $pending = $store->verifiedPendingTransition('run-a');
        $this->assertNotNull($pending);
        $store->finalizeVerifiedTransition('run-a', $pending->identity);

        $this->assertSame(0, $inner->rangeForCalls);
        $this->assertSame([1, 2], array_map(static fn (RuntimeEvent $event): int => $event->seq, $sink->emitted));
    }

    public function testColdVerifiedFinalizationPublishesThroughExistingRangeWithoutRetainingBatch(): void
    {
        $inner = new RecordingEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $store = $this->store($inner, $sink, true);

        $store->appendTransition([
            new RunEvent('run-a', 0, 0, RunEventTypeEnum::RunStarted->value, []),
            new RunEvent('run-a', 0, 0, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
        ], ['run_id' => 'run-a', 'predecessor_seq' => 0]);
        $pending = $store->verifiedPendingTransition('run-a');
        $this->assertNotNull($pending);

        // Simulate a restarted stream wrapper that lost the hot batch.
        $cold = $this->store($inner, $sink, true);
        $cold->finalizeVerifiedTransition('run-a', $pending->identity);

        $this->assertSame(0, $inner->rangeForCalls);
        $this->assertSame(1, $inner->verifiedPendingBatchCalls);
        $this->assertSame([1, 2], array_map(static fn (RuntimeEvent $event): int => $event->seq, $sink->emitted));
        $this->assertNull($cold->verifiedPendingTransition('run-a'));
        $this->assertNull($inner->verifiedPendingTransition('run-a'));
    }

    public function testIdentityMismatchRefusesBeforePublicationOrArchiveRead(): void
    {
        $inner = new RecordingEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $store = $this->store($inner, $sink, true);

        $store->appendTransition([
            new RunEvent('run-a', 0, 0, RunEventTypeEnum::RunStarted->value, []),
        ], ['run_id' => 'run-a', 'predecessor_seq' => 0]);

        try {
            $store->finalizeVerifiedTransition('run-a', 'stale-identity');
            $this->fail('Stale identities must refuse before publication.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('identity changed', $exception->getMessage());
        }

        $this->assertSame(0, $inner->rangeForCalls);
        $this->assertSame([], $sink->emitted);
        $this->assertNotNull($store->verifiedPendingTransition('run-a'));
    }

    public function testCorruptColdBatchRefusesPublication(): void
    {
        $inner = new RecordingEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $store = $this->store($inner, $sink, true);

        $store->appendTransition([
            new RunEvent('run-a', 0, 0, RunEventTypeEnum::RunStarted->value, []),
            new RunEvent('run-a', 0, 0, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
        ], ['run_id' => 'run-a', 'predecessor_seq' => 0]);
        $pending = $store->verifiedPendingTransition('run-a');
        $this->assertNotNull($pending);
        unset($inner->eventsByRun['run-a'][1]);

        $cold = $this->store($inner, $sink, true);
        try {
            $cold->finalizeVerifiedTransition('run-a', $pending->identity);
            $this->fail('Corrupt verified batches must refuse publication.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('could not be reconstructed', $exception->getMessage());
        }

        $this->assertSame([], $sink->emitted);
        $this->assertNotNull($inner->verifiedPendingTransition('run-a'));
    }

    public function testRangeForDelegatesWithoutEmitting(): void
    {
        $inner = new RecordingEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $store = $this->store($inner, $sink, true);

        iterator_to_array($store->rangeFor('run-a', 1, 1));

        $this->assertSame(1, $inner->rangeForCalls);
        $this->assertSame([], $sink->emitted);
    }

    public function testStreamingDisabledSkipsStdoutEmit(): void
    {
        $inner = new RecordingEventStore();
        $sink = new RecordingCommittedStdoutSink();
        $store = $this->store($inner, $sink, false);

        $store->appendTransition([
            new RunEvent('run-a', 0, 0, RunEventTypeEnum::RunStarted->value, []),
        ], ['run_id' => 'run-a', 'predecessor_seq' => 0]);
        $pending = $store->verifiedPendingTransition('run-a');
        $this->assertNotNull($pending);
        $store->finalizeVerifiedTransition('run-a', $pending->identity);

        $this->assertCount(1, $inner->appended);
        $this->assertCount(0, $sink->emitted);
        $this->assertSame(0, $inner->rangeForCalls);
    }

    private function store(
        PreparedTransitionEventStoreInterface $inner,
        RuntimeEventSinkInterface $sink,
        bool $stream,
    ): StreamingCommittedRuntimeEventStore {
        $mapper = new RuntimeEventMapper(new RuntimeEventTranslator(
            new EventDispatcher(),
            new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()),
        ));

        return new StreamingCommittedRuntimeEventStore($inner, $mapper, $sink, $stream);
    }
}

/**
 * @internal
 */
final class RecordingEventStore implements PreparedTransitionEventStoreInterface
{
    /** @var list<RunEvent> */
    public array $appended = [];

    /** @var array<string, list<RunEvent>> */
    public array $eventsByRun = [];

    public int $rangeForCalls = 0;

    public int $verifiedPendingBatchCalls = 0;

    /** @var array<string, VerifiedTransitionDTO> */
    private array $pending = [];

    public function appendTransition(array $events, array $work): array
    {
        $runId = $events[0]->runId ?? $work['run_id'] ?? null;
        if (!\is_string($runId) || '' === $runId) {
            throw new \InvalidArgumentException('Prepared transition requires run identity.');
        }

        $out = [];
        foreach ($events as $event) {
            $seq = $event->seq > 0 ? $event->seq : (\count($this->eventsByRun[$runId] ?? []) + 1);
            $persisted = new RunEvent($event->runId, $seq, $event->turnNo, $event->type, $event->payload, $event->createdAt);
            $this->appended[] = $persisted;
            $this->eventsByRun[$runId][] = $persisted;
            $out[] = $persisted;
        }
        $this->pending[$runId] = new VerifiedTransitionDTO(
            hash('sha256', serialize([$work, $out])),
            $work,
            array_map(static fn (RunEvent $event): int => $event->seq, $out),
        );

        return $out;
    }

    public function verifiedPendingTransition(string $runId): ?VerifiedTransitionDTO
    {
        return $this->pending[$runId] ?? null;
    }

    public function verifiedPendingBatch(string $runId, string $identity): array
    {
        ++$this->verifiedPendingBatchCalls;
        $pending = $this->verifiedPendingTransition($runId);
        if (null === $pending || $pending->identity !== $identity) {
            throw new \RuntimeException('Fixture transition identity mismatch.');
        }

        $wanted = array_fill_keys($pending->eventSequences, true);
        $out = [];
        foreach ($this->eventsByRun[$runId] ?? [] as $event) {
            if (isset($wanted[$event->seq])) {
                $out[] = $event;
            }
        }
        if (\count($out) !== \count($pending->eventSequences)) {
            throw new \RuntimeException('Verified transition batch could not be reconstructed for stream publication.');
        }

        return $out;
    }

    public function finalizeVerifiedTransition(string $runId, string $identity): void
    {
        if (($this->pending[$runId]->identity ?? null) !== $identity) {
            throw new \RuntimeException('Fixture transition identity mismatch.');
        }
        unset($this->pending[$runId]);
    }

    public function assertTransitionReady(string $runId): void
    {
    }

    public function latestSequenceFor(string $runId): ?int
    {
        $events = $this->allFor($runId);

        return [] === $events ? null : $events[array_key_last($events)]->seq;
    }

    public function firstFor(string $runId): ?RunEvent
    {
        $events = $this->allFor($runId);

        return $events[0] ?? null;
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        ++$this->rangeForCalls;
        foreach ($this->eventsByRun[$runId] ?? [] as $event) {
            if ($event->seq >= $startSeq && $event->seq <= $endSeq) {
                yield $event;
            }
        }
    }

    public function reverseFor(string $runId): iterable
    {
        return array_reverse($this->allFor($runId));
    }

    public function allFor(string $runId): array
    {
        return array_values($this->eventsByRun[$runId] ?? []);
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
