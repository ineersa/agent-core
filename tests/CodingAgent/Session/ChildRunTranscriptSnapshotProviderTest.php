<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Session\ChildRunTranscriptSnapshotProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(ChildRunTranscriptSnapshotProvider::class)]
final class ChildRunTranscriptSnapshotProviderTest extends TestCase
{
    private string $childRunId = 'child-run-snapshot-a';

    public function testSnapshotProjectsMappedChildEventsAndMaxSeq(): void
    {
        $events = [
            $this->runEvent(RunEventTypeEnum::TurnAdvanced->value, 1, 1, ['turn_no' => 1, 'step_id' => 's1']),
            $this->runEvent(RunEventTypeEnum::LlmStepCompleted->value, 5, 1, ['text' => 'Child scout answer']),
            $this->runEvent(RunEventTypeEnum::ToolBatchCommitted->value, 6, 1, ['batch_id' => 'b1']),
        ];

        $provider = $this->createProvider($events);
        $snapshot = $provider->snapshot($this->childRunId);

        $this->assertSame(5, $snapshot->maxSeq);
        $this->assertCount(2, $snapshot->replayEvents);
        $this->assertSame(5, $snapshot->replayEvents[1]->seq);

        $joined = implode("\n", array_map(static fn (TranscriptBlock $b): string => $b->text, $snapshot->transcriptBlocks));
        $this->assertStringContainsString('Child scout answer', $joined);
    }

    public function testSecondSnapshotDoesNotLeakBlocksFromFirstRun(): void
    {
        $eventsRunA = [
            $this->runEvent(RunEventTypeEnum::LlmStepCompleted->value, 2, 1, ['text' => 'Run A only'], runId: 'child-a'),
        ];
        $eventsRunB = [
            $this->runEvent(RunEventTypeEnum::LlmStepCompleted->value, 3, 1, ['text' => 'Run B only'], runId: 'child-b'),
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('allFor')->willReturnMap([
            ['child-a', $eventsRunA],
            ['child-b', $eventsRunB],
        ]);

        $provider = $this->createProviderWithStore($store);

        $first = $provider->snapshot('child-a');
        $second = $provider->snapshot('child-b');

        $firstText = implode("\n", array_map(static fn (TranscriptBlock $b): string => $b->text, $first->transcriptBlocks));
        $secondText = implode("\n", array_map(static fn (TranscriptBlock $b): string => $b->text, $second->transcriptBlocks));

        $this->assertStringContainsString('Run A only', $firstText);
        $this->assertStringNotContainsString('Run B only', $firstText);
        $this->assertStringContainsString('Run B only', $secondText);
        $this->assertStringNotContainsString('Run A only', $secondText);
    }

    /** @param list<RunEvent> $events */
    private function createProvider(array $events): ChildRunTranscriptSnapshotProvider
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('allFor')->willReturn($events);

        return $this->createProviderWithStore($store);
    }

    private function createProviderWithStore(EventStoreInterface $store): ChildRunTranscriptSnapshotProvider
    {
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $translator = new RuntimeEventTranslator($eventDispatcher);
        $eventMapper = new RuntimeEventMapper($translator);

        $dispatcher = new EventDispatcher();
        $projectionState = new TranscriptProjectionState();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $transcriptProjector = new TranscriptProjector($dispatcher, $projectionState);

        return new ChildRunTranscriptSnapshotProvider($store, $eventMapper, $transcriptProjector);
    }

    /** @param array<string, mixed> $payload */
    private function runEvent(string $type, int $seq, int $turnNo, array $payload = [], ?string $runId = null): RunEvent
    {
        return new RunEvent(
            runId: $runId ?? $this->childRunId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
        );
    }
}
