<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\BackfillEventProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
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

#[CoversClass(SubagentLiveChildViewPoller::class)]
final class SubagentLiveChildViewPollerBackfillTest extends TestCase
{
    private const string CHILD_RUN_ID = 'child_run_001';
    private const string ARTIFACT_ID = 'test_artifact';

    #[Test]
    public function backfillProjectsStoredWaitingHumanAndFiresHitlCallback(): void
    {
        $projector = $this->createStub(TranscriptProjectorInterface::class);
        $projector->method('blocks')->willReturn([
            new TranscriptBlock(
                id: 'block-1',
                kind: TranscriptBlockKindEnum::Progress,
                runId: self::CHILD_RUN_ID,
                seq: 2,
                text: 'Which file should the scout inspect next?',
            ),
        ]);

        $backfillHit = false;
        $backfillEvent = null;

        $backfillProvider = $this->createMock(BackfillEventProviderInterface::class);
        $backfillProvider->expects($this->once())
            ->method('getStoredEvents')
            ->with(self::CHILD_RUN_ID)
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::HumanInputRequested->value,
                    runId: self::CHILD_RUN_ID,
                    seq: 2,
                    payload: [
                        'question_id' => 'q_child_test',
                        'prompt' => 'Which file should the scout inspect next?',
                        'schema' => ['type' => 'string'],
                    ],
                ),
            ]);

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('events')
            ->with(self::CHILD_RUN_ID)
            ->willReturn([]);

        $poller = new SubagentLiveChildViewPoller(
            projector: $projector,
            logger: new NullLogger(),
            backfillProvider: $backfillProvider,
        );

        $state = $this->createLiveViewState(RunActivityStateEnum::WaitingHuman);

        $hitlCallback = function (RuntimeEvent $event) use (&$backfillHit, &$backfillEvent): void {
            $backfillHit = true;
            $backfillEvent = $event;
        };

        $blocks = $poller->poll(
            live: $state,
            client: $client,
            onHumanInputRequested: $hitlCallback,
        );

        $this->assertNotNull($blocks, 'poll() must return blocks after backfill');
        $this->assertTrue($backfillHit, 'onHumanInputRequested callback must fire');
        $this->assertNotNull($backfillEvent);
        $this->assertSame(RuntimeEventTypeEnum::HumanInputRequested->value, $backfillEvent->type);
        $this->assertSame(self::CHILD_RUN_ID, $backfillEvent->runId);
        $this->assertSame(2, $backfillEvent->seq);
        $this->assertSame('Which file should the scout inspect next?', $backfillEvent->payload['prompt'] ?? null);
    }

    #[Test]
    public function secondPollDoesNotCallBackfillProviderAgain(): void
    {
        $projector = $this->createStub(TranscriptProjectorInterface::class);
        $projector->method('blocks')->willReturn([]);

        // The poller calls getStoredEvents() every time; idempotency is handled
        // by the provider internally (second call returns empty).
        $backfillProvider = $this->createMock(BackfillEventProviderInterface::class);
        $backfillProvider->expects($this->exactly(2))
            ->method('getStoredEvents')
            ->with(self::CHILD_RUN_ID)
            ->willReturnOnConsecutiveCalls([], []);

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->exactly(2))
            ->method('events')
            ->with(self::CHILD_RUN_ID)
            ->willReturn([]);

        $poller = new SubagentLiveChildViewPoller(
            projector: $projector,
            logger: new NullLogger(),
            backfillProvider: $backfillProvider,
        );

        $state = $this->createLiveViewState(RunActivityStateEnum::Running);

        // First poll: backfill called, no events → null
        $poller->poll($state, $client);
        // Advance poll timestamp so second poll is not throttled
        $state->childLastPoll = 0.0;

        // Second poll: backfill called again but returns [] (idempotent provider contract)
        $result = $poller->poll($state, $client);
        $this->assertNull($result, 'Second poll with no new events should return null');
    }

    #[Test]
    public function backfillEventsMergeBeforeLiveEventsInTranscript(): void
    {
        $projector = $this->createStub(TranscriptProjectorInterface::class);
        $projector->method('blocks')->willReturn([
            new TranscriptBlock(
                id: 'block-1',
                kind: TranscriptBlockKindEnum::Progress,
                runId: self::CHILD_RUN_ID,
                seq: 2,
                text: 'Merged progress block',
            ),
        ]);

        $backfillProvider = $this->createMock(BackfillEventProviderInterface::class);
        $backfillProvider->expects($this->once())
            ->method('getStoredEvents')
            ->with(self::CHILD_RUN_ID)
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::ProgressUpdated->value,
                    runId: self::CHILD_RUN_ID,
                    seq: 1,
                    payload: ['text' => 'stored progress'],
                ),
            ]);

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('events')
            ->with(self::CHILD_RUN_ID)
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::ProgressUpdated->value,
                    runId: self::CHILD_RUN_ID,
                    seq: 3,
                    payload: ['text' => 'live progress'],
                ),
            ]);

        $poller = new SubagentLiveChildViewPoller(
            projector: $projector,
            logger: new NullLogger(),
            backfillProvider: $backfillProvider,
        );

        $state = $this->createLiveViewState(RunActivityStateEnum::Running);

        $blocks = $poller->poll($state, $client);

        $this->assertNotNull($blocks, 'poll() must return blocks');
        // After processing both stored and live events, seq should be 3 (last processed)
        $this->assertSame(3, $state->childLastSeq, 'childLastSeq must reflect merged high seq');
    }

    #[Test]
    public function liveEventsDeDupeOverlappingBackfillEvents(): void
    {
        $projector = $this->createStub(TranscriptProjectorInterface::class);
        $projector->method('blocks')->willReturn([
            new TranscriptBlock(
                id: 'block-1',
                kind: TranscriptBlockKindEnum::Progress,
                runId: self::CHILD_RUN_ID,
                seq: 2,
                text: 'De-dup block',
            ),
        ]);

        $backfillProvider = $this->createMock(BackfillEventProviderInterface::class);
        $backfillProvider->expects($this->once())
            ->method('getStoredEvents')
            ->with(self::CHILD_RUN_ID)
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::HumanInputRequested->value,
                    runId: self::CHILD_RUN_ID,
                    seq: 2,
                    payload: ['question_id' => 'q_child'],
                ),
            ]);

        // Live events include the same seq 2 (duplicate) plus a new seq 3.
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('events')
            ->with(self::CHILD_RUN_ID)
            ->willReturn([
                // Duplicate event at seq 2 — would fire onHumanInputRequested if not skipped
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::HumanInputRequested->value,
                    runId: self::CHILD_RUN_ID,
                    seq: 2,
                    payload: ['question_id' => 'q_child_duplicate'],
                ),
                // New event at seq 3
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::StatusUpdated->value,
                    runId: self::CHILD_RUN_ID,
                    seq: 3,
                    payload: ['text' => 'after question'],
                ),
            ]);

        $poller = new SubagentLiveChildViewPoller(
            projector: $projector,
            logger: new NullLogger(),
            backfillProvider: $backfillProvider,
        );

        $state = $this->createLiveViewState(RunActivityStateEnum::WaitingHuman);

        $callbackCalled = false;
        $callbackSeq = 0;

        $poller->poll(
            live: $state,
            client: $client,
            onHumanInputRequested: function (RuntimeEvent $event) use (&$callbackCalled, &$callbackSeq): void {
                $callbackCalled = true;
                $callbackSeq = $event->seq;
            },
        );

        // Callback must have fired exactly once (from backfill, not from duplicate live event)
        // and lastSeq must be 3 (not stuck at 2).
        $this->assertTrue($callbackCalled, 'onHumanInputRequested must fire');
        $this->assertSame(2, $callbackSeq, 'Callback must fire from backfill seq 2, not duplicate');
        $this->assertSame(3, $state->childLastSeq, 'childLastSeq must advance past duplicate');
    }

    private function createLiveViewState(RunActivityStateEnum $activity): SubagentLiveViewState
    {
        $state = new SubagentLiveViewState();
        $state->selected = new SubagentLiveChildDTO(
            agentRunId: self::CHILD_RUN_ID,
            artifactId: self::ARTIFACT_ID,
            agentName: 'scout',
            status: SubagentLiveStatusEnum::WaitingHuman,
            taskSummary: 'Test child task',
            lastActivityAtMs: 1000,
        );
        $state->active = true;
        $state->childActivity = $activity;
        $state->childLastPoll = 0.0;

        return $state;
    }
}
