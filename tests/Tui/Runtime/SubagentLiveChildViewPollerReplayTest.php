<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Process\RuntimeEventPerRunCompactBuffer;
use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\CompactionProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\HitlProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\AssistantTextStreamSubscriber;
use Ineersa\CodingAgent\Runtime\Stream\LlmStreamDispatchObserver;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\SubagentLiveViewState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageStart;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(SubagentLiveChildViewPoller::class)]
final class SubagentLiveChildViewPollerReplayTest extends TestCase
{
    private const string CHILD_RUN_ID = 'child_run_replay';

    #[Test]
    public function enteringUnobservedChildShowsCompletedNativeCommentaryBeforeDurableStepCompletion(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();
        $sink = new class($buffer) implements RuntimeEventSinkInterface {
            public function __construct(private readonly RuntimeEventPerRunCompactBuffer $buffer)
            {
            }

            public function emit(RuntimeEvent $event): void
            {
                $this->buffer->ingest($event, observedRun: false);
            }
        };
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AssistantTextStreamSubscriber($sink));
        $observer = new LlmStreamDispatchObserver($dispatcher);
        $observer->onStreamStart(self::CHILD_RUN_ID, 'unfinished-step');
        foreach (['Inspecting files.', 'Running checks.'] as $index => $text) {
            $item = ['type' => 'message', 'id' => 'msg_'.$index, 'role' => 'assistant', 'phase' => 'commentary', 'content' => [['type' => 'output_text', 'text' => $text]]];
            $observer->onDelta(self::CHILD_RUN_ID, 'unfinished-step', new MessageStart($item, $index));
            $observer->onDelta(self::CHILD_RUN_ID, 'unfinished-step', new TextDelta($text));
            $completion = new MessageComplete(MessageItem::toText($item), $index);
            $observer->onDelta(self::CHILD_RUN_ID, 'unfinished-step', $completion);
            $observer->onDelta(self::CHILD_RUN_ID, 'unfinished-step', $completion);
        }

        $poller = new SubagentLiveChildViewPoller($this->childLiveProjector(), new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());
        $live = $this->liveState();
        $snapshot = new ChildRunTranscriptSnapshotDTO([], [new RuntimeEvent(
            RuntimeEventTypeEnum::AssistantMessageCompleted->value, self::CHILD_RUN_ID, 5,
            ['message_id' => 'previous-step', 'text' => 'Previous durable answer.'],
        )], 5);
        $harness = new VirtualTuiHarness();
        $harness->screen()->setWorkingVisible(false);
        $harness->screen()->setTranscriptBlocks($poller->replaySnapshot($live, $snapshot));
        $this->assertStringContainsString('Previous durable answer.', $harness->plainScreenText());

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('events')->with(self::CHILD_RUN_ID, 5)
            ->willReturnCallback(static fn (): iterable => $buffer->drain(self::CHILD_RUN_ID));
        $changes = $poller->poll($live, $client);
        $this->assertNotNull($changes);
        $harness->screen()->applyTranscriptChangeSet($changes);

        $screen = $harness->plainScreenText();
        foreach (['Previous durable answer.', 'Inspecting files.', 'Running checks.'] as $text) {
            $this->assertSame(1, substr_count($screen, $text), 'Snapshot and native tail must appear once before llm_step_completed.');
        }
        $this->assertLessThan(strpos($screen, 'Running checks.'), strpos($screen, 'Inspecting files.'));
        $this->assertStringNotContainsString('[commentary]', $screen);
        $this->assertSame(['commentary', 'commentary'], array_column(array_column(\array_slice($live->childTranscript(), 1), 'meta'), 'phase'));
        $this->assertSame(5, $live->childLastSeq, 'The native checkpoint remains transient, not durable history.');
        $this->assertSame(0, $buffer->totalTailCount());
    }

    #[Test]
    public function replaySnapshotSetsChildLastSeqAndFiresUnresolvedHitlOnly(): void
    {
        $projector = new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState());
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());

        $snapshot = new ChildRunTranscriptSnapshotDTO(
            transcriptBlocks: [
                new TranscriptBlock(
                    id: 'block-hitl',
                    kind: TranscriptBlockKindEnum::Progress,
                    runId: self::CHILD_RUN_ID,
                    seq: 3,
                    text: 'Approve scout plan?',
                ),
            ],
            replayEvents: [
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::HumanInputRequested->value,
                    runId: self::CHILD_RUN_ID,
                    seq: 1,
                    payload: [
                        'question_id' => 'q_resolved',
                        'prompt' => 'old',
                        'schema' => ['type' => 'string'],
                    ],
                ),
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::HumanInputAnswered->value,
                    runId: self::CHILD_RUN_ID,
                    seq: 2,
                    payload: [
                        'question_id' => 'q_resolved',
                        'answer' => 'done',
                    ],
                ),
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::HumanInputRequested->value,
                    runId: self::CHILD_RUN_ID,
                    seq: 3,
                    payload: [
                        'question_id' => 'q_open',
                        'prompt' => 'Approve scout plan?',
                        'schema' => ['type' => 'string'],
                    ],
                ),
            ],
            maxSeq: 3,
        );

        $live = $this->liveState();
        $hit = [];

        $blocks = $poller->replaySnapshot(
            $live,
            $snapshot,
            onHumanInputRequested: static function (RuntimeEvent $event) use (&$hit): void {
                $hit[] = (string) ($event->payload['question_id'] ?? '');
            },
        );

        $this->assertSame(['q_open'], $hit);
        $this->assertSame(3, $live->childLastSeq);
        $this->assertSame('Approve scout plan?', $blocks[0]->text);
    }

    #[Test]
    public function pollReturnsOnlyIncrementalLiveTranscriptChanges(): void
    {
        $projector = $this->childLiveProjector();
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());

        $live = $this->liveState();
        $poller->replaySnapshot(
            $live,
            new ChildRunTranscriptSnapshotDTO(
                transcriptBlocks: [],
                replayEvents: [
                    new RuntimeEvent(
                        RuntimeEventTypeEnum::AssistantThinkingStarted->value,
                        self::CHILD_RUN_ID,
                        0,
                        ['block_id' => 'thinking-1'],
                    ),
                    new RuntimeEvent(
                        RuntimeEventTypeEnum::AssistantThinkingDelta->value,
                        self::CHILD_RUN_ID,
                        0,
                        ['block_id' => 'thinking-1', 'thinking' => 'A long reasoning prefix. '],
                    ),
                ],
                maxSeq: 1,
            ),
        );

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('events')
            ->with(self::CHILD_RUN_ID, 1)
            ->willReturn([
                new RuntimeEvent(
                    RuntimeEventTypeEnum::AssistantThinkingDelta->value,
                    self::CHILD_RUN_ID,
                    0,
                    ['block_id' => 'thinking-1', 'thinking' => 'followed by one streamed suffix.'],
                ),
                new RuntimeEvent(RuntimeEventTypeEnum::StatusUpdated->value, self::CHILD_RUN_ID, 2, ['text' => 'live only']),
            ]);

        $live->childLastPoll = 0.0;
        $changes = $poller->poll($live, $client);

        $this->assertNotNull($changes);
        $this->assertFalse($changes->isFull(), 'Live child streaming must not replace the full mounted transcript.');
        $this->assertCount(1, $changes->upserts);
        $this->assertSame('A long reasoning prefix. followed by one streamed suffix.', $changes->upserts[0]->text);
        $this->assertSame(2, $live->childLastSeq);
        $this->assertSame($changes->upserts[0]->text, $live->childTranscript()[0]->text);
    }

    #[Test]
    public function pollRemovesLoadingPlaceholderWhenFirstLiveTranscriptChangesArrive(): void
    {
        $projector = $this->childLiveProjector();
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());
        $live = $this->liveState();
        $live->replaceChildTranscript($live->placeholderTranscriptFor($live->selected), entryOnly: true);

        $harness = new VirtualTuiHarness();
        $harness->screen()->setWorkingVisible(false);
        $harness->screen()->setTranscriptBlocks($live->childTranscript());
        $this->assertStringContainsString('Loading live view', $harness->plainScreenText());

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('events')
            ->with(self::CHILD_RUN_ID, 0)
            ->willReturn([
                new RuntimeEvent(
                    RuntimeEventTypeEnum::AssistantThinkingStarted->value,
                    self::CHILD_RUN_ID,
                    0,
                    ['block_id' => 'thinking-1'],
                ),
                new RuntimeEvent(
                    RuntimeEventTypeEnum::AssistantThinkingDelta->value,
                    self::CHILD_RUN_ID,
                    0,
                    ['block_id' => 'thinking-1', 'thinking' => 'The child has started.'],
                ),
            ]);

        $changes = $poller->poll($live, $client);

        $this->assertNotNull($changes);
        $this->assertFalse($changes->isFull());
        $this->assertSame(['subagent-live-placeholder'], $changes->removals);
        $harness->screen()->applyTranscriptChangeSet($changes);
        $this->assertStringNotContainsString('Loading live view', $harness->plainScreenText());
        $this->assertStringContainsString('The child has started.', $harness->plainScreenText());
        $this->assertCount(1, $changes->upserts);
        $this->assertSame('thinking-1', $changes->upserts[0]->id);
        $this->assertCount(1, $live->childTranscript());
        $this->assertSame('thinking-1', $live->childTranscript()[0]->id);
    }

    #[Test]
    public function pollSkipsEventsAtOrBelowChildLastSeqAfterReplay(): void
    {
        $projector = $this->childLiveProjector();
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());

        $live = $this->liveState();
        $poller->replaySnapshot(
            $live,
            new ChildRunTranscriptSnapshotDTO(
                [
                    new TranscriptBlock('b1', TranscriptBlockKindEnum::AssistantMessage, self::CHILD_RUN_ID, 5, 'replayed'),
                ],
                [],
                5,
            ),
        );
        $harness = new VirtualTuiHarness();
        $harness->screen()->setWorkingVisible(false);
        $harness->screen()->setTranscriptBlocks($live->childTranscript());
        $this->assertStringContainsString('replayed', $harness->plainScreenText());

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->exactly(2))
            ->method('events')
            ->with(self::CHILD_RUN_ID, $this->anything())
            ->willReturnOnConsecutiveCalls(
                [
                    new RuntimeEvent(RuntimeEventTypeEnum::AssistantMessageCompleted->value, self::CHILD_RUN_ID, 3, ['text' => 'stale']),
                ],
                [
                    new RuntimeEvent(RuntimeEventTypeEnum::AssistantMessageCompleted->value, self::CHILD_RUN_ID, 6, ['text' => 'live tail']),
                ],
            );

        $live->childLastPoll = 0.0;
        $this->assertNull($poller->poll($live, $client), 'seq 3 must be skipped when childLastSeq is 5');

        $live->childLastPoll = 0.0;
        $changes = $poller->poll($live, $client);
        $this->assertNotNull($changes);
        $this->assertSame(['b1'], $changes->removals, 'mounted snapshot fallbacks must be removed when live projection replaces them');
        $this->assertSame(6, $live->childLastSeq);
        $harness->screen()->applyTranscriptChangeSet($changes);
        $this->assertStringNotContainsString('replayed', $harness->plainScreenText());
        $this->assertStringContainsString('live tail', $harness->plainScreenText());
    }

    #[Test]
    public function pollRetainsFailedSuffixUntilItApplies(): void
    {
        $projector = $this->createMock(TranscriptProjectorInterface::class);
        $projector->method('reset');
        $projector->method('blocks')->willReturn([]);
        $projector->method('drainChanges')->willReturn(TranscriptChangeSet::incremental([]));
        $projector->method('replaceProjectedBlocks');
        $projector->expects($this->exactly(2))->method('accept')->willReturnOnConsecutiveCalls(
            $this->throwException(new \RuntimeException('projection failed')),
            null,
        );
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());
        $live = $this->liveState();
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::AssistantTextDelta->value,
            runId: self::CHILD_RUN_ID,
            seq: 2,
            payload: ['block_id' => 'text-1', 'delta' => 'text'],
        );
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('events')->with(self::CHILD_RUN_ID, $this->anything())->willReturn([$event]);
        try {
            $poller->poll($live, $client);
            $this->fail('Expected projection failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('projection failed', $exception->getMessage());
        }
        $this->assertSame(0, $live->childLastSeq);

        $live->childLastPoll = 0.0;
        $poller->poll($live, $client);

        $this->assertSame(2, $live->childLastSeq);
    }

    #[Test]
    public function mountedLongChildHistoryAppliesContentAndToolPatchesWithoutMaterializingBlocks(): void
    {
        $inner = $this->childLiveProjector();
        $projector = $this->createMock(TranscriptProjectorInterface::class);
        $projector->method('reset')->willReturnCallback($inner->reset(...));
        $projector->method('accept')->willReturnCallback($inner->accept(...));
        $projector->method('drainChanges')->willReturnCallback($inner->drainChanges(...));
        $projector->expects($this->once())->method('blocks')->willReturnCallback($inner->blocks(...));
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());
        $live = $this->liveState();
        $events = [];
        for ($i = 0; $i < 200; ++$i) {
            $events[] = new RuntimeEvent('assistant.message_completed', self::CHILD_RUN_ID, 0, ['message_id' => 'history-'.$i, 'text' => 'History '.$i]);
        }
        $events[] = new RuntimeEvent('assistant.text_started', self::CHILD_RUN_ID, 0, ['block_id' => 'live-text']);
        $events[] = new RuntimeEvent('assistant.text_delta', self::CHILD_RUN_ID, 0, ['block_id' => 'live-text', 'text' => 'Live prefix ']);
        $events[] = new RuntimeEvent('tool_call.started', self::CHILD_RUN_ID, 0, ['tool_call_id' => 'call', 'tool_name' => 'bash']);
        $events[] = new RuntimeEvent('tool_call.arguments_completed', self::CHILD_RUN_ID, 0, ['tool_call_id' => 'call', 'arguments' => ['command' => 'echo before']]);
        $events[] = new RuntimeEvent('tool_execution.started', self::CHILD_RUN_ID, 0, ['tool_call_id' => 'call', 'tool_name' => 'bash', 'arguments' => ['command' => 'echo before']]);
        $harness = new VirtualTuiHarness(columns: 100, rows: 24);
        $harness->screen()->setWorkingVisible(false);
        $harness->screen()->setTranscriptBlocks($poller->replaySnapshot($live, new ChildRunTranscriptSnapshotDTO([], $events, 1)));
        $this->assertStringContainsString('echo before', $harness->plainScreenText());
        $mounted = new \ReflectionProperty($harness->screen(), 'transcriptWidget')->getValue($harness->screen());
        $historyWrapper = $mounted->all()[0];
        $card = $mounted->all()[201];
        $native = $card->all()[0];
        $this->pollMounted($poller, $live, $harness, [
            new RuntimeEvent('assistant.text_delta', self::CHILD_RUN_ID, 0, ['block_id' => 'live-text', 'text' => 'fresh suffix']),
            new RuntimeEvent('tool_call.arguments_delta', self::CHILD_RUN_ID, 0, ['tool_call_id' => 'call', 'partial_json' => 'hidden partial argument']),
        ]);
        $this->assertStringContainsString('Live prefix fresh suffix', $harness->plainScreenText());
        $this->assertSame($historyWrapper, $mounted->all()[0]);
        $this->assertSame($card, $mounted->all()[201]);
        $this->assertSame($native, $card->all()[0]);
        $this->pollMounted($poller, $live, $harness, [new RuntimeEvent('tool_execution.completed', self::CHILD_RUN_ID, 2, ['tool_call_id' => 'call', 'result' => 'Child tool done'])]);
        $this->assertStringContainsString('Child tool done', $harness->plainScreenText());
        $this->assertFalse($live->childTranscript()[202]->streaming);
        $client = $this->createStub(AgentSessionClient::class);
        $client->method('events')->willReturn([new RuntimeEvent('status.updated', self::CHILD_RUN_ID, 3, ['text' => 'working'])]);
        $live->childLastPoll = 0.0;
        $this->assertNull($poller->poll($live, $client));
        $this->assertSame($historyWrapper, $mounted->all()[0]);
        $this->assertSame(3, $live->childLastSeq);
    }

    #[Test]
    public function mountedChildPreservesReorderSuppressionAndHistoryResetThenSwitchesCleanly(): void
    {
        $poller = new SubagentLiveChildViewPoller($this->childLiveProjector(), new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());
        $live = $this->liveState();
        $harness = new VirtualTuiHarness(rows: 50);
        $harness->screen()->setWorkingVisible(false);
        $seed = [
            new RuntimeEvent('assistant.text_started', self::CHILD_RUN_ID, 0, ['message_id' => 'm', 'block_id' => 'later', 'output_index' => 1]),
            new RuntimeEvent('assistant.text_delta', self::CHILD_RUN_ID, 0, ['block_id' => 'later', 'text' => 'SECOND_NATIVE']),
            new RuntimeEvent('tool_call.started', self::CHILD_RUN_ID, 0, ['tool_call_id' => 'phantom', 'tool_name' => 'phantom-tool']),
        ];
        $harness->screen()->setTranscriptBlocks($poller->replaySnapshot($live, new ChildRunTranscriptSnapshotDTO([], $seed, 1)));
        $this->assertStringContainsString('phantom-tool', $harness->plainScreenText());
        $changes = $this->pollMounted($poller, $live, $harness, [
            new RuntimeEvent('tool_call.arguments_completed', self::CHILD_RUN_ID, 0, ['tool_call_id' => 'phantom', 'arguments' => []]),
            new RuntimeEvent('assistant.message_completed', self::CHILD_RUN_ID, 2, ['message_id' => 'm', 'content' => [
                ['type' => 'text', 'block_id' => 'earlier', 'text' => 'FIRST_NATIVE', 'output_index' => 0],
                ['type' => 'text', 'block_id' => 'later', 'text' => 'SECOND_NATIVE', 'output_index' => 1],
            ]]),
        ]);
        $this->assertContains('tool_call_phantom', $changes->removals);
        $text = $harness->plainScreenText();
        $this->assertStringNotContainsString('phantom-tool', $text);
        $this->assertLessThan(strpos($text, 'SECOND_NATIVE'), strpos($text, 'FIRST_NATIVE'));
        $this->assertSame(['earlier', 'later'], array_column($live->childTranscript(), 'id'));
        $changes = $this->pollMounted($poller, $live, $harness, [
            new RuntimeEvent('run.history_position_changed', self::CHILD_RUN_ID, 3),
            new RuntimeEvent('assistant.message_completed', self::CHILD_RUN_ID, 4, ['message_id' => 'replacement', 'text' => 'RESET_TAIL']),
        ]);
        $this->assertTrue($changes->isFull());
        $this->assertStringContainsString('RESET_TAIL', $harness->plainScreenText());
        $this->assertStringNotContainsString('FIRST_NATIVE', $harness->plainScreenText());
        $poller->resetProjection();
        $live->enter(new SubagentLiveChildDTO('other-child', 'other-artifact', 'other', SubagentLiveStatusEnum::Running, 'other task', 1, 'provider/model', 'medium'));
        $harness->screen()->setTranscriptBlocks($poller->replaySnapshot($live, new ChildRunTranscriptSnapshotDTO([], [new RuntimeEvent('assistant.message_completed', 'other-child', 1, ['message_id' => 'other', 'text' => 'OTHER_CHILD'])], 1)));
        $this->assertStringContainsString('OTHER_CHILD', $harness->plainScreenText());
        $this->assertStringNotContainsString('RESET_TAIL', $harness->plainScreenText());
        $live->exit();
        $harness->screen()->setTranscriptBlocks($live->childTranscript());
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('events');
        $this->assertNull($poller->poll($live, $client));
        $this->assertStringNotContainsString('OTHER_CHILD', $harness->plainScreenText());
    }

    #[Test]
    public function mountedChildRetainsReorderWhenAMovedBlockIsUpdatedBeforeDrain(): void
    {
        $projector = $this->childLiveProjector();
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());
        $live = $this->liveState();
        $harness = new VirtualTuiHarness(rows: 30);
        $harness->screen()->setWorkingVisible(false);
        $harness->screen()->setTranscriptBlocks($poller->replaySnapshot($live, new ChildRunTranscriptSnapshotDTO([], [
            new RuntimeEvent('assistant.text_started', self::CHILD_RUN_ID, 0, ['message_id' => 'm', 'block_id' => 'later', 'output_index' => 1]),
            new RuntimeEvent('assistant.text_delta', self::CHILD_RUN_ID, 0, ['block_id' => 'later', 'text' => 'SECOND_NATIVE']),
        ], 1)));
        $this->assertStringContainsString('SECOND_NATIVE', $harness->plainScreenText());

        // Canonical completion and a delayed worker checkpoint share one drain.
        // The canonical tool call keeps the child active while the tail arrives.
        $changes = $this->pollMounted($poller, $live, $harness, [
            new RuntimeEvent('assistant.message_completed', self::CHILD_RUN_ID, 2, ['message_id' => 'm', 'content' => [
                ['type' => 'text', 'block_id' => 'earlier', 'text' => 'FIRST_NATIVE', 'output_index' => 0],
                ['type' => 'text', 'block_id' => 'later', 'text' => 'SECOND_NATIVE', 'output_index' => 1],
                ['type' => 'tool_call', 'id' => 'active', 'name' => 'bash', 'arguments' => ['command' => 'echo CHILD_TOOL']],
            ]]),
            new RuntimeEvent('assistant.text_completed', self::CHILD_RUN_ID, 0, ['message_id' => 'm', 'block_id' => 'later', 'text' => 'SECOND_UPDATED', 'output_index' => 1]),
        ]);
        $expectedOrder = ['earlier', 'later', 'tool_call_active'];
        $this->assertSame($expectedOrder, array_column($projector->blocks(), 'id'));
        $this->assertSame($expectedOrder, array_column($live->childTranscript(), 'id'));
        $this->assertFalse($changes->isFull());
        $this->assertContains('later', $changes->removals);
        $this->assertSame('SECOND_UPDATED', $live->childTranscript()[1]->text);
        $text = $harness->plainScreenText();
        $this->assertStringContainsString('FIRST_NATIVE', $text);
        $this->assertStringContainsString('SECOND_UPDATED', $text);
        $this->assertStringContainsString('CHILD_TOOL', $text);
        $this->assertLessThan(strpos($text, 'SECOND_UPDATED'), strpos($text, 'FIRST_NATIVE'));
        $this->assertLessThan(strpos($text, 'CHILD_TOOL'), strpos($text, 'SECOND_UPDATED'));

        $harness->screen()->setTranscriptBlocks($live->childTranscript());
        $text = $harness->plainScreenText();
        $this->assertLessThan(strpos($text, 'SECOND_UPDATED'), strpos($text, 'FIRST_NATIVE'));
        $this->assertLessThan(strpos($text, 'CHILD_TOOL'), strpos($text, 'SECOND_UPDATED'));
        $changes = $this->pollMounted($poller, $live, $harness, [
            new RuntimeEvent('assistant.text_completed', self::CHILD_RUN_ID, 0, ['message_id' => 'm', 'block_id' => 'later', 'text' => 'SECOND_UPDATED_AGAIN', 'output_index' => 1]),
        ]);
        $this->assertFalse($changes->isFull());
        $this->assertSame([], $changes->removals, 'The completed drain must clear pending reinsertion.');
        $this->assertSame($expectedOrder, array_column($live->childTranscript(), 'id'));
        $this->assertStringContainsString('SECOND_UPDATED_AGAIN', $harness->plainScreenText());
    }

    #[Test]
    public function mountedRetentionEvictsOldLocalNoticesAndKeepsTheVisibleTailAcrossResize(): void
    {
        $poller = new SubagentLiveChildViewPoller($this->childLiveProjector(), new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());
        $live = $this->liveState();
        $harness = new VirtualTuiHarness(columns: 100, rows: 24);
        $harness->screen()->setWorkingVisible(false);
        $poller->replaySnapshot($live, new ChildRunTranscriptSnapshotDTO([], [new RuntimeEvent('user.message_submitted', self::CHILD_RUN_ID, 1, ['message_id' => 'old', 'text' => 'OLD_SEGMENT'])], 1));
        $live->appendChildTranscriptBlock(new TranscriptBlock('old-local', TranscriptBlockKindEnum::Error, self::CHILD_RUN_ID, 1000, 'OLD_LOCAL_NOTICE'));
        $harness->screen()->setTranscriptBlocks($live->childTranscript());
        $this->pollMounted($poller, $live, $harness, [new RuntimeEvent('compaction.completed', self::CHILD_RUN_ID, 2)]);
        $this->assertStringContainsString('OLD_LOCAL_NOTICE', $harness->plainScreenText(), 'Ordinary deltas must retain local errors.');
        $events = [];
        for ($i = 3; $i < 60; ++$i) {
            $events[] = new RuntimeEvent('user.message_submitted', self::CHILD_RUN_ID, $i, ['message_id' => 'retained-'.$i, 'text' => 'Retained child row '.$i]);
        }
        $this->pollMounted($poller, $live, $harness, $events);
        $live->appendChildTranscriptBlock(new TranscriptBlock('new-local', TranscriptBlockKindEnum::Error, self::CHILD_RUN_ID, 1001, 'NEW_LOCAL_NOTICE'));
        $harness->screen()->setTranscriptBlocks($live->childTranscript());
        $changes = $this->pollMounted($poller, $live, $harness, [new RuntimeEvent('compaction.completed', self::CHILD_RUN_ID, 60)]);
        $this->assertTrue($changes->isFull(), 'Retention must publish local-block eviction to the mounted screen.');
        $this->assertNotContains('old-local', array_column($live->childTranscript(), 'id'));
        $this->assertContains('new-local', array_column($live->childTranscript(), 'id'));
        $text = $harness->plainScreenText();
        $this->assertStringContainsString('NEW_LOCAL_NOTICE', $text);
        $this->assertStringContainsString('Retained child row 59', $text);
        $this->assertStringNotContainsString('OLD_LOCAL_NOTICE', $text);
        $harness->terminal()->simulateResize(60, 24);
        $text = $harness->plainScreenText();
        $this->assertStringContainsString('NEW_LOCAL_NOTICE', $text);
        $this->assertStringContainsString('Retained child row 59', $text);
        $this->assertStringNotContainsString('OLD_SEGMENT', $text);
    }

    #[Test]
    public function mountedFullChangeKeepsItsOrderWhileReplacingEntryOnlyBlocks(): void
    {
        $live = $this->liveState();
        $live->replaceChildTranscript($live->placeholderTranscriptFor($live->selected), entryOnly: true);
        $harness = new VirtualTuiHarness();
        $harness->screen()->setWorkingVisible(false);
        $harness->screen()->setTranscriptBlocks($live->childTranscript());
        $blocks = [
            new TranscriptBlock('second-id', TranscriptBlockKindEnum::AssistantMessage, self::CHILD_RUN_ID, 2, 'FIRST_VISIBLE'),
            new TranscriptBlock('first-id', TranscriptBlockKindEnum::AssistantMessage, self::CHILD_RUN_ID, 1, 'SECOND_VISIBLE'),
        ];
        $projector = $this->createMock(TranscriptProjectorInterface::class);
        $projector->expects($this->never())->method('blocks');
        $projector->method('drainChanges')->willReturn(TranscriptChangeSet::full($blocks));
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());
        $changes = $this->pollMounted($poller, $live, $harness, [new RuntimeEvent('status.updated', self::CHILD_RUN_ID, 1)]);
        $this->assertTrue($changes->isFull());
        $this->assertSame($blocks, $live->childTranscript());
        $text = $harness->plainScreenText();
        $this->assertStringNotContainsString('Loading live view', $text);
        $this->assertLessThan(strpos($text, 'SECOND_VISIBLE'), strpos($text, 'FIRST_VISIBLE'));
    }

    #[Test]
    public function mountedHistoryResetSurvivesAFailedTailProjectionUntilRetry(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $fail = true;
        $dispatcher->addListener('assistant.message_completed', static function () use (&$fail): void {
            if ($fail) {
                $fail = false;
                throw new \RuntimeException('tail refused');
            }
        }, 100);
        $projector = new TranscriptProjector($dispatcher, new TranscriptProjectionState());
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());
        $live = $this->liveState();
        $harness = new VirtualTuiHarness();
        $harness->screen()->setWorkingVisible(false);
        $poller->replaySnapshot($live, new ChildRunTranscriptSnapshotDTO([new TranscriptBlock('old', TranscriptBlockKindEnum::AssistantMessage, self::CHILD_RUN_ID, 1, 'OLD_BEFORE_RETRY')], [], 1));
        $harness->screen()->setTranscriptBlocks($live->childTranscript());
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('events')->willReturn([
            new RuntimeEvent('run.history_position_changed', self::CHILD_RUN_ID, 2),
            new RuntimeEvent('assistant.message_completed', self::CHILD_RUN_ID, 3, ['message_id' => 'new', 'text' => 'TAIL_AFTER_RETRY']),
        ]);
        try {
            $poller->poll($live, $client);
            $this->fail('The first tail projection must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('tail refused', $exception->getMessage());
        }
        $this->assertSame(2, $live->childLastSeq);
        $this->assertStringContainsString('OLD_BEFORE_RETRY', $harness->plainScreenText());
        $live->childLastPoll = 0.0;
        $changes = $poller->poll($live, $client);
        $this->assertNotNull($changes);
        $this->assertTrue($changes->isFull());
        $harness->screen()->applyTranscriptChangeSet($changes);
        $this->assertSame(3, $live->childLastSeq);
        $this->assertStringContainsString('TAIL_AFTER_RETRY', $harness->plainScreenText());
        $this->assertStringNotContainsString('OLD_BEFORE_RETRY', $harness->plainScreenText());
    }

    /** @param list<RuntimeEvent> $events */
    private function pollMounted(SubagentLiveChildViewPoller $poller, SubagentLiveViewState $live, VirtualTuiHarness $harness, array $events): TranscriptChangeSet
    {
        $client = $this->createStub(AgentSessionClient::class);
        $client->method('events')->willReturn($events);
        $live->childLastPoll = 0.0;
        $changes = $poller->poll($live, $client);
        $this->assertNotNull($changes);
        $harness->screen()->applyTranscriptChangeSet($changes);

        return $changes;
    }

    private function childLiveProjector(): TranscriptProjector
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $dispatcher->addSubscriber(new HitlProjectionSubscriber());
        $dispatcher->addSubscriber(new ToolProjectionSubscriber(new SubagentProgressDisplayFormatter(), SubagentProgressSerializerTestSupport::denormalizer()));
        $dispatcher->addSubscriber(new CompactionProjectionSubscriber());
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());

        return new TranscriptProjector($dispatcher, new TranscriptProjectionState());
    }

    private function liveState(): SubagentLiveViewState
    {
        $state = new SubagentLiveViewState();
        $state->active = true;
        $state->selected = new SubagentLiveChildDTO(
            agentRunId: self::CHILD_RUN_ID,
            artifactId: 'art_replay',
            agentName: 'scout',
            status: SubagentLiveStatusEnum::Running,
            taskSummary: 'replay test',
            lastActivityAtMs: 1,
            model: 'deepseek/deepseek-v4-flash',
            reasoning: 'medium',
        );
        $state->childActivity = RunActivityStateEnum::Running;
        $state->childLastPoll = 0.0;

        return $state;
    }
}
