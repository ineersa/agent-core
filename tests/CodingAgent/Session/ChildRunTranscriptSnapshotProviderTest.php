<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
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
use Ineersa\CodingAgent\Session\ChildRunTranscriptSnapshotProvider;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
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
            $this->runEvent(RunEventTypeEnum::LlmStepCompleted->value, 5, 1, $this->assistantPayload('Child scout answer')),
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
            $this->runEvent(RunEventTypeEnum::LlmStepCompleted->value, 2, 1, $this->assistantPayload('Run A only'), runId: 'child-a'),
        ];
        $eventsRunB = [
            $this->runEvent(RunEventTypeEnum::LlmStepCompleted->value, 3, 1, $this->assistantPayload('Run B only'), runId: 'child-b'),
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
        $translator = new RuntimeEventTranslator($eventDispatcher, new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()));
        $eventMapper = new RuntimeEventMapper($translator);

        $dispatcher = new EventDispatcher();
        $projectionState = new TranscriptProjectionState();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $dispatcher->addSubscriber(new ToolProjectionSubscriber(new SubagentProgressDisplayFormatter(), SubagentProgressSerializerTestSupport::denormalizer()));
        $transcriptProjector = new TranscriptProjector($dispatcher, $projectionState);

        return new ChildRunTranscriptSnapshotProvider($store, $eventMapper, $transcriptProjector);
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
