<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\HitlProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\SubagentLiveViewState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(SubagentLiveChildViewPoller::class)]
final class SubagentLiveChildViewPollerReplayTest extends TestCase
{
    private const string CHILD_RUN_ID = 'child_run_replay';

    #[Test]
    public function replaySnapshotSetsChildLastSeqAndFiresHitlCallback(): void
    {
        $projector = new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState());
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());

        $snapshot = new ChildRunTranscriptSnapshotDTO(
            transcriptBlocks: [
                new TranscriptBlock(
                    id: 'block-hitl',
                    kind: TranscriptBlockKindEnum::Progress,
                    runId: self::CHILD_RUN_ID,
                    seq: 2,
                    text: 'Approve scout plan?',
                ),
            ],
            replayEvents: [
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::HumanInputRequested->value,
                    runId: self::CHILD_RUN_ID,
                    seq: 2,
                    payload: [
                        'question_id' => 'q_replay',
                        'prompt' => 'Approve scout plan?',
                        'schema' => ['type' => 'string'],
                    ],
                ),
            ],
            maxSeq: 2,
        );

        $live = $this->liveState();
        $hit = false;
        $hitRunId = null;

        $blocks = $poller->replaySnapshot(
            $live,
            $snapshot,
            onHumanInputRequested: static function (RuntimeEvent $event) use (&$hit, &$hitRunId): void {
                $hit = true;
                $hitRunId = $event->runId;
            },
        );

        $this->assertTrue($hit);
        $this->assertSame(self::CHILD_RUN_ID, $hitRunId);
        $this->assertSame(2, $live->childLastSeq);
        $this->assertSame('Approve scout plan?', $blocks[0]->text);
        $this->assertArrayHasKey(self::CHILD_RUN_ID, $live->childCaches);
        $this->assertSame(2, $live->childCaches[self::CHILD_RUN_ID]['lastSeq']);
    }

    #[Test]
    public function pollReturnsOnlyIncrementalLiveTranscriptChangesAndPersistsCache(): void
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
        $this->assertSame(2, $live->childCaches[self::CHILD_RUN_ID]['lastSeq']);
        $this->assertSame($changes->upserts[0]->text, $live->childTranscript[0]->text);
    }

    #[Test]
    public function pollRemovesLoadingPlaceholderWhenFirstLiveTranscriptChangesArrive(): void
    {
        $projector = $this->childLiveProjector();
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());
        $live = $this->liveState();
        $live->childTranscript = $live->placeholderTranscriptFor($live->selected);

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
        $this->assertCount(1, $changes->upserts);
        $this->assertSame('thinking-1', $changes->upserts[0]->id);
        $this->assertCount(1, $live->childTranscript);
        $this->assertSame('thinking-1', $live->childTranscript[0]->id);
    }

    #[Test]
    public function pollSkipsEventsAtOrBelowChildLastSeqAfterReplay(): void
    {
        $projector = new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState());
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
    }

    #[Test]
    public function cachedReentryReprojectsReplayAndLiveEvents(): void
    {
        $projector = $this->childLiveProjector();
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());

        $live = $this->liveState();
        $poller->replaySnapshot(
            $live,
            new ChildRunTranscriptSnapshotDTO(
                [
                    new TranscriptBlock('b-seed', TranscriptBlockKindEnum::Question, self::CHILD_RUN_ID, 1, 'seed'),
                ],
                [
                    new RuntimeEvent(
                        RuntimeEventTypeEnum::HumanInputRequested->value,
                        self::CHILD_RUN_ID,
                        1,
                        [
                            'question_id' => 'q_seed',
                            'prompt' => 'seed',
                        ],
                    ),
                ],
                1,
            ),
        );

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('events')
            ->with(self::CHILD_RUN_ID, $this->anything())
            ->willReturn([
                new RuntimeEvent(
                    RuntimeEventTypeEnum::HumanInputRequested->value,
                    self::CHILD_RUN_ID,
                    2,
                    [
                        'question_id' => 'q_live',
                        'prompt' => 'live tail',
                    ],
                ),
            ]);

        $live->childLastPoll = 0.0;
        $poller->poll($live, $client);

        $this->assertCount(2, $live->childReplayEvents);

        $reentrySnapshot = new ChildRunTranscriptSnapshotDTO(
            $live->childTranscript,
            $live->childReplayEvents,
            $live->childLastSeq,
        );

        $blocks = $poller->replaySnapshot($live, $reentrySnapshot);

        $texts = array_map(static fn (TranscriptBlock $block): string => $block->text, $blocks);
        $this->assertContains('seed', $texts);
        $this->assertContains('live tail', $texts);
        $this->assertCount(2, $live->childReplayEvents);
        $this->assertCount(2, $live->childCaches[self::CHILD_RUN_ID]['replayEvents']);
    }

    #[Test]
    public function pollRetainsFailedSuffixUntilItApplies(): void
    {
        $projector = $this->createMock(TranscriptProjectorInterface::class);
        $projector->method('reset');
        $projector->method('blocks')->willReturn([]);
        $projector->method('drainChanges')->willReturn(TranscriptChangeSet::incremental([]));
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

    private function childLiveProjector(): TranscriptProjector
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $dispatcher->addSubscriber(new HitlProjectionSubscriber());

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
