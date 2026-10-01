<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\ChildRunPhysicalSuffixReaderInterface;
use Ineersa\CodingAgent\Runtime\ChildRunTranscriptSnapshotProvider;
use Ineersa\CodingAgent\Runtime\ChildRunTranscriptSnapshotStoreInterface;
use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Session\RunState\RunStateStoreInterface;
use Ineersa\CodingAgent\Tests\Session\RunState\InMemoryRunStateStore;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(ChildRunTranscriptSnapshotProvider::class)]
final class ChildRunTranscriptSnapshotProviderTest extends TestCase
{
    private string $childRunId = 'child-run-snapshot-a';

    public function testSnapshotProjectsMappedChildEventsAndMaxSeq(): void
    {
        $events = [
            $this->runEvent(RunEventTypeEnum::TurnAdvanced->value, 1, 1, ['turn_no' => 1, 'step_id' => 's1']),
            $this->runEvent(RunEventTypeEnum::LlmStepCompleted->value, 5, 1, $this->assistantPayload('Child scout answer')),
            $this->runEvent(RunEventTypeEnum::ToolBatchCommitted->value, 6, 1, ['batch_id' => 'b1']),
        ];

        $provider = $this->createProvider($events);
        $snapshot = $provider->snapshot($this->childRunId);

        $this->assertSame(6, $snapshot->maxSeq);
        $this->assertSame([], $snapshot->pendingHumanInputEvents);
        $this->assertSame([], $snapshot->pendingToolQuestionEvents);

        $joined = implode("\n", array_map(static fn (TranscriptBlock $b): string => $b->text, $snapshot->transcriptBlocks));
        $this->assertStringContainsString('Child scout answer', $joined);
    }

    public function testSecondSnapshotDoesNotLeakBlocksFromFirstRun(): void
    {
        $eventsRunA = [
            $this->runEvent(RunEventTypeEnum::LlmStepCompleted->value, 2, 1, $this->assistantPayload('Run A only'), runId: 'child-a'),
        ];
        $eventsRunB = [
            $this->runEvent(RunEventTypeEnum::LlmStepCompleted->value, 3, 1, $this->assistantPayload('Run B only'), runId: 'child-b'),
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('rangeFor')->willReturnCallback(
            static function (string $runId) use ($eventsRunA, $eventsRunB): array {
                return match ($runId) {
                    'child-a' => $eventsRunA,
                    'child-b' => $eventsRunB,
                    default => [],
                };
            },
        );

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

    public function testSnapshotProjectsDirectShellToolCallAndResultPair(): void
    {
        // Thesis: child live-view/replay uses the same mapper+projector path;
        // direct-shell tool_execution_start/end with arguments must yield the
        // finalized ToolCall (command:) + ToolResult pair, not an orphan result.
        $events = [
            $this->runEvent(RunEventTypeEnum::ToolExecutionStart->value, 1, 1, [
                'tool_call_id' => 'sh_child_1',
                'tool_name' => 'bash',
                'order_index' => 0,
                'arguments' => ['command' => 'echo child-shell'],
            ]),
            $this->runEvent(RunEventTypeEnum::ToolExecutionEnd->value, 2, 1, (new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()))->toEventPayload(new ToolCallResult(
                runId: $this->childRunId,
                turnNo: 1,
                stepId: 'shell-step',
                attempt: 1,
                idempotencyKey: 'shell-result',
                toolCallId: 'sh_child_1',
                orderIndex: 0,
                result: ['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => "child-shell\n"]], 'arguments' => ['command' => 'echo child-shell']],
            ))),
        ];

        $snapshot = $this->createProvider($events)->snapshot($this->childRunId);

        $this->assertSame(2, $snapshot->maxSeq);
        $this->assertCount(2, $snapshot->transcriptBlocks);

        $call = $snapshot->transcriptBlocks[0];
        $result = $snapshot->transcriptBlocks[1];

        $this->assertSame(TranscriptBlockKindEnum::ToolCall, $call->kind);
        $this->assertSame('tool_call_sh_child_1', $call->id);
        $this->assertSame('bash(command: "echo child-shell")', $call->text);
        $this->assertSame(['command' => 'echo child-shell'], $call->meta['arguments'] ?? null);
        $this->assertArrayNotHasKey('timeout', $call->meta['arguments'] ?? []);

        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $result->kind);
        $this->assertSame('tool_result_sh_child_1', $result->id);
        $this->assertSame("child-shell\n", $result->text);
    }

    public function testEmptyTranscriptStillRestoresPendingLocalToolQuestion(): void
    {
        $events = $this->createStub(EventStoreInterface::class);
        $events->method('rangeFor')->willReturn([]);
        $questions = $this->createMock(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class);
        $questions->expects($this->once())->method('findPendingQuestionsForRun')->with($this->childRunId)->willReturn([
            \Ineersa\CodingAgent\Entity\ToolQuestion::create(
                requestId: 'pending', runId: $this->childRunId, toolCallId: 'tc', toolName: 'bash',
                pid: 42, logPath: '/tmp/test.log', commandPreview: 'command', prompt: 'Background?',
            ),
        ]);

        $snapshot = $this->createProviderWithStore($events, $questions)->snapshot($this->childRunId);

        $this->assertSame([], $snapshot->transcriptBlocks);
        $this->assertSame(0, $snapshot->maxSeq);
        $this->assertCount(1, $snapshot->pendingToolQuestionEvents);
        $this->assertSame('tool_question.requested', $snapshot->pendingToolQuestionEvents[0]->type);
        $this->assertSame('pending', $snapshot->pendingToolQuestionEvents[0]->payload['request_id']);
    }

    public function testSnapshotDoesNotCallAllFor(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects($this->once())->method('rangeFor')->willReturn([
            $this->runEvent(RunEventTypeEnum::LlmStepCompleted->value, 1, 1, $this->assistantPayload('ok')),
        ]);

        $snapshot = $this->createProviderWithStore($store)->snapshot($this->childRunId);
        $this->assertSame(1, $snapshot->maxSeq);
    }

    public function testRepeatedEnterReusesSharedSnapshotWithoutArchiveRead(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects($this->once())->method('rangeFor')->willReturn([
            $this->runEvent(RunEventTypeEnum::LlmStepCompleted->value, 2, 1, $this->assistantPayload('cached answer')),
        ]);

        $runStateStore = new InMemoryRunStateStore();
        $runStateStore->initialize(new RunState(
            runId: $this->childRunId,
            status: RunStatus::Running,
            turnNo: 1,
            lastSeq: 2,
            model: 'test-model',
        ));
        $snapshotStore = new InMemoryChildRunTranscriptSnapshotStore();
        $provider = $this->createProviderWithStore(
            $store,
            snapshotStore: $snapshotStore,
            runStateStore: $runStateStore,
        );

        $first = $provider->snapshot($this->childRunId);
        $second = $provider->snapshot($this->childRunId);

        $this->assertSame(2, $first->maxSeq);
        $this->assertSame(2, $second->maxSeq);
        $this->assertSame(1, $snapshotStore->rememberCalls);
        $joined = implode("\n", array_map(static fn (TranscriptBlock $b): string => $b->text, $second->transcriptBlocks));
        $this->assertStringContainsString('cached answer', $joined);
    }

    public function testSuffixAdvanceUsesPhysicalReaderWithoutRangeFor(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects($this->never())->method('rangeFor');
        $store->expects($this->never())->method('latestSequenceFor');

        $suffixReader = $this->createMock(ChildRunPhysicalSuffixReaderInterface::class);
        $suffixReader->expects($this->once())
            ->method('readAfterSeq')
            ->with($this->childRunId, 2)
            ->willReturn([
                $this->runEvent(RunEventTypeEnum::LlmStepCompleted->value, 5, 1, $this->assistantPayload('suffix answer')),
            ]);

        $runStateStore = new InMemoryRunStateStore();
        $runStateStore->initialize(new RunState(
            runId: $this->childRunId,
            status: RunStatus::Running,
            turnNo: 1,
            lastSeq: 5,
            model: 'test-model',
        ));

        $snapshotStore = new InMemoryChildRunTranscriptSnapshotStore();
        $snapshotStore->remember($this->childRunId, new \Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO(
            transcriptBlocks: [],
            resume: \Ineersa\CodingAgent\Runtime\Contract\SessionResumeProjectionDTO::empty(),
            pendingHumanInputEvents: [],
            pendingToolQuestionEvents: [],
            maxSeq: 2,
        ));

        $provider = $this->createProviderWithStore(
            $store,
            snapshotStore: $snapshotStore,
            runStateStore: $runStateStore,
            physicalSuffixReader: $suffixReader,
        );

        $snapshot = $provider->snapshot($this->childRunId);
        $this->assertSame(5, $snapshot->maxSeq);
        $joined = implode("\n", array_map(static fn (TranscriptBlock $b): string => $b->text, $snapshot->transcriptBlocks));
        $this->assertStringContainsString('suffix answer', $joined);
        $this->assertSame(2, $snapshotStore->rememberCalls);
    }

    public function testReuseFailsClosedWhenSharedStateMissing(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects($this->never())->method('rangeFor');

        $snapshotStore = new InMemoryChildRunTranscriptSnapshotStore();
        $snapshotStore->remember($this->childRunId, new \Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO(
            transcriptBlocks: [],
            resume: \Ineersa\CodingAgent\Runtime\Contract\SessionResumeProjectionDTO::empty(),
            pendingHumanInputEvents: [],
            pendingToolQuestionEvents: [],
            maxSeq: 2,
        ));

        $provider = $this->createProviderWithStore($store, snapshotStore: $snapshotStore, runStateStore: new InMemoryRunStateStore());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('initialize via startup/new-run/recovery before ordinary lookups');
        $provider->snapshot($this->childRunId);
    }

    public function testReuseFailsClosedWhenSharedStateNotReady(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects($this->never())->method('rangeFor');

        $runStateStore = new InMemoryRunStateStore();
        $runStateStore->initialize(new RunState(
            runId: $this->childRunId,
            status: RunStatus::Running,
            turnNo: 1,
            lastSeq: 2,
            model: 'test-model',
        ));
        $runStateStore->withdrawForCommit($this->childRunId);

        $snapshotStore = new InMemoryChildRunTranscriptSnapshotStore();
        $snapshotStore->remember($this->childRunId, new \Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO(
            transcriptBlocks: [],
            resume: \Ineersa\CodingAgent\Runtime\Contract\SessionResumeProjectionDTO::empty(),
            pendingHumanInputEvents: [],
            pendingToolQuestionEvents: [],
            maxSeq: 2,
        ));

        $provider = $this->createProviderWithStore($store, snapshotStore: $snapshotStore, runStateStore: $runStateStore);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not ready');
        $provider->snapshot($this->childRunId);
    }

    public function testSnapshotAheadOfSharedStateFailsClosed(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects($this->never())->method('rangeFor');

        $runStateStore = new InMemoryRunStateStore();
        $runStateStore->initialize(new RunState(
            runId: $this->childRunId,
            status: RunStatus::Running,
            turnNo: 1,
            lastSeq: 2,
            model: 'test-model',
        ));
        $snapshotStore = new InMemoryChildRunTranscriptSnapshotStore();
        $snapshotStore->remember($this->childRunId, new \Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO(
            transcriptBlocks: [],
            resume: \Ineersa\CodingAgent\Runtime\Contract\SessionResumeProjectionDTO::empty(),
            pendingHumanInputEvents: [],
            pendingToolQuestionEvents: [],
            maxSeq: 5,
        ));

        $provider = $this->createProviderWithStore($store, snapshotStore: $snapshotStore, runStateStore: $runStateStore);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ahead of shared state');
        $provider->snapshot($this->childRunId);
    }

    /** @param list<RunEvent> $events */
    private function createProvider(array $events): ChildRunTranscriptSnapshotProvider
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('rangeFor')->willReturn($events);

        return $this->createProviderWithStore($store);
    }

    private function createProviderWithStore(
        EventStoreInterface $store,
        ?\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface $questions = null,
        ?ChildRunTranscriptSnapshotStoreInterface $snapshotStore = null,
        ?RunStateStoreInterface $runStateStore = null,
        ?ChildRunPhysicalSuffixReaderInterface $physicalSuffixReader = null,
    ): ChildRunTranscriptSnapshotProvider {
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $translator = new RuntimeEventTranslator($eventDispatcher, new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()));
        $eventMapper = new RuntimeEventMapper($translator);

        $dispatcher = new EventDispatcher();
        $projectionState = new TranscriptProjectionState();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $dispatcher->addSubscriber(new ToolProjectionSubscriber(new SubagentProgressDisplayFormatter(), SubagentProgressSerializerTestSupport::denormalizer()));
        $transcriptProjector = new TranscriptProjector($dispatcher, $projectionState);

        return new ChildRunTranscriptSnapshotProvider(
            $store,
            $eventMapper,
            $transcriptProjector,
            $questions ?? $this->createStub(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class),
            $snapshotStore ?? new InMemoryChildRunTranscriptSnapshotStore(),
            $runStateStore ?? new InMemoryRunStateStore(),
            $physicalSuffixReader ?? $this->createStub(ChildRunPhysicalSuffixReaderInterface::class),
            new RunLockManager(new LockFactory(new InMemoryStore())),
            new NullLogger(),
        );
    }

    /** @return array<string, mixed> */
    private function assistantPayload(string $text): array
    {
        return [
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => $text]],
            ],
        ];
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
