<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
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
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger());

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
    public function pollUsesOnlyLiveClientEventsAndPersistsCache(): void
    {
        $projector = new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState());
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger());

        $live = $this->liveState();
        $poller->replaySnapshot(
            $live,
            new ChildRunTranscriptSnapshotDTO(
                [
                    new TranscriptBlock('b0', TranscriptBlockKindEnum::AssistantMessage, self::CHILD_RUN_ID, 1, 'seed'),
                ],
                [],
                1,
            ),
        );

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('events')
            ->with(self::CHILD_RUN_ID)
            ->willReturn([
                new RuntimeEvent(RuntimeEventTypeEnum::ProgressUpdated->value, self::CHILD_RUN_ID, 2, ['text' => 'live only']),
            ]);

        $live->childLastPoll = 0.0;
        $result = $poller->poll($live, $client);

        $this->assertNotNull($result);
        $this->assertSame(2, $live->childLastSeq);
        $this->assertSame(2, $live->childCaches[self::CHILD_RUN_ID]['lastSeq']);
    }

    #[Test]
    public function pollSkipsEventsAtOrBelowChildLastSeqAfterReplay(): void
    {
        $projector = new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState());
        $poller = new SubagentLiveChildViewPoller($projector, new NullLogger());

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
            ->with(self::CHILD_RUN_ID)
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
        $this->assertNotNull($poller->poll($live, $client));
        $this->assertSame(6, $live->childLastSeq);
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
        );
        $state->childActivity = RunActivityStateEnum::Running;
        $state->childLastPoll = 0.0;

        return $state;
    }
}
