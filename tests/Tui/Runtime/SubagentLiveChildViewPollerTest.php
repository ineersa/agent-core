<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\SubagentLiveViewState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SubagentLiveChildViewPollerTest extends TestCase
{
    #[Test]
    public function forkLiveViewFilterHidesBootstrapUserMessagesButKeepsCompactSummary(): void
    {
        $poller = $this->createPoller();
        $blocks = [
            new TranscriptBlock(
                id: 'bootstrap-task',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'child-run-fork',
                seq: 1,
                text: 'Fork delegated handoff task',
                meta: ['bootstrap' => true, 'source' => 'run_started'],
            ),
            new TranscriptBlock(
                id: 'bootstrap-summary',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'child-run-fork',
                seq: 2,
                text: 'Fresh compact summary for fork',
                meta: ['bootstrap' => true, 'source' => 'run_started', 'compact_summary' => true],
            ),
            new TranscriptBlock(
                id: 'live-1',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'child-run-fork',
                seq: 3,
                text: 'Child live steer',
            ),
        ];

        $filtered = $this->invokeFilter($poller, $blocks);
        $texts = array_map(static fn (TranscriptBlock $block): string => $block->text, $filtered);

        $this->assertNotContains('Fork delegated handoff task', $texts);
        $this->assertContains('Fresh compact summary for fork', $texts);
        $this->assertContains('Child live steer', $texts);
    }

    #[Test]
    public function forkReplaySnapshotAppliesCompactSummaryFilterOnEnter(): void
    {
        $poller = $this->createPoller();
        $live = $this->forkLiveState();
        $snapshot = new ChildRunTranscriptSnapshotDTO(
            transcriptBlocks: [],
            replayEvents: [
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::RunStarted->value,
                    runId: 'child-run-fork',
                    seq: 1,
                    payload: [
                        'step_id' => 'start',
                        'user_messages' => [
                            ['message_id' => 'init_task', 'text' => 'Fork delegated handoff task'],
                            ['message_id' => 'init_summary', 'text' => 'Fresh compact summary for fork', 'compact_summary' => true],
                        ],
                    ],
                ),
            ],
            maxSeq: 1,
        );

        $transcript = $poller->replaySnapshot($live, $snapshot);
        $texts = array_map(static fn (TranscriptBlock $block): string => $block->text, $transcript);

        $this->assertSame(['Fresh compact summary for fork'], $texts);
        $this->assertSame('init_summary', $transcript[0]->id);
    }

    #[Test]
    public function forkLivePollKeepsCompactSummaryAfterAssistantEvent(): void
    {
        $poller = $this->createPoller();
        $live = $this->forkLiveState();
        $snapshot = new ChildRunTranscriptSnapshotDTO(
            transcriptBlocks: [],
            replayEvents: [
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::RunStarted->value,
                    runId: 'child-run-fork',
                    seq: 1,
                    payload: [
                        'step_id' => 'start',
                        'user_messages' => [
                            ['message_id' => 'init_task', 'text' => 'Fork delegated handoff task'],
                            ['message_id' => 'init_summary', 'text' => 'Fresh compact summary for fork', 'compact_summary' => true],
                        ],
                    ],
                ),
            ],
            maxSeq: 1,
        );
        $poller->replaySnapshot($live, $snapshot);

        $client = $this->createStub(AgentSessionClient::class);
        $client->method('events')->willReturn([
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantMessageCompleted->value,
                runId: 'child-run-fork',
                seq: 2,
                payload: ['message_id' => 'a1', 'text' => 'Fork assistant output'],
            ),
        ]);
        $live->childLastPoll = 0.0;

        $transcript = $poller->poll($live, $client);
        $this->assertNotNull($transcript);
        $texts = array_map(static fn (TranscriptBlock $block): string => $block->text, $transcript);

        $this->assertContains('Fresh compact summary for fork', $texts);
        $this->assertNotContains('Fork delegated handoff task', $texts);
        $this->assertContains('Fork assistant output', $texts);
    }

    #[Test]
    public function nonForkReplaySnapshotDoesNotStripBootstrapUserMessages(): void
    {
        $poller = $this->createPoller();
        $live = new SubagentLiveViewState();
        $live->enter(new SubagentLiveChildDTO(
            agentRunId: 'child-run-scout',
            artifactId: 'agent_scout',
            agentName: 'scout',
            status: SubagentLiveStatusEnum::Running,
            taskSummary: 'scout task',
            lastActivityAtMs: 1,
        ));
        $snapshot = new ChildRunTranscriptSnapshotDTO(
            transcriptBlocks: [],
            replayEvents: [
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::RunStarted->value,
                    runId: 'child-run-scout',
                    seq: 1,
                    payload: [
                        'step_id' => 'start',
                        'user_messages' => [
                            ['message_id' => 'init_task', 'text' => 'Scout bootstrap task'],
                        ],
                    ],
                ),
            ],
            maxSeq: 1,
        );

        $transcript = $poller->replaySnapshot($live, $snapshot);
        $texts = array_map(static fn (TranscriptBlock $block): string => $block->text, $transcript);

        $this->assertContains('Scout bootstrap task', $texts);
    }

    private function createPoller(): SubagentLiveChildViewPoller
    {
        $dispatcher = new EventDispatcher();
        $state = new TranscriptProjectionState();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());

        return new SubagentLiveChildViewPoller(
            new TranscriptProjector($dispatcher, $state),
            new NullLogger(),
        );
    }

    private function forkLiveState(): SubagentLiveViewState
    {
        $live = new SubagentLiveViewState();
        $live->enter(new SubagentLiveChildDTO(
            agentRunId: 'child-run-fork',
            artifactId: 'agent_fork',
            agentName: 'fork',
            status: SubagentLiveStatusEnum::Running,
            taskSummary: 'fork task',
            lastActivityAtMs: 1,
        ));

        return $live;
    }

    /** @param list<TranscriptBlock> $blocks */
    private function invokeFilter(SubagentLiveChildViewPoller $poller, array $blocks): array
    {
        $method = new \ReflectionMethod(SubagentLiveChildViewPoller::class, 'filterForkLiveTranscriptBlocks');

        return $method->invoke($poller, $blocks);
    }
}
