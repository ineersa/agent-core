<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Integration-style tests for RuntimeEventPoller with mocked dependencies.
 *
 * @covers \Ineersa\Tui\Runtime\RuntimeEventPoller
 * @covers \Ineersa\Tui\Runtime\ActivityStateMachine
 * @covers \Ineersa\Tui\Runtime\UsageProjection
 */
#[AllowMockObjectsWithoutExpectations]
final class RuntimeEventPollerTest extends TestCase
{
    private TuiSessionState $state;
    private AgentSessionClient&MockObject $client;
    private TranscriptProjectorInterface&MockObject $projector;
    private LoggerInterface&MockObject $logger;
    private TurnTreeProviderInterface&MockObject $turnTreeProvider;
    private RuntimeEventPoller $poller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = new TuiSessionState('test-session');
        $this->state->handle = new RunHandle(
            runId: 'test-run',
        );

        $this->client = $this->createMock(AgentSessionClient::class);
        $this->projector = $this->createMock(TranscriptProjectorInterface::class);
        $this->turnTreeProvider = $this->createMock(TurnTreeProviderInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier($this->projector),
            $this->logger,
            new RuntimeExceptionBoundary(
                $this->createStub(EventDispatcherInterface::class),
            ),
            $this->turnTreeProvider,
        );
    }

    public function testPollReturnsNullWhenNoRunHandle(): void
    {
        $state = new TuiSessionState('test-session');
        $result = $this->poller->poll($state, $this->client);

        self::assertNull($result);
    }

    public function testPollReturnsNullForEmptyEvents(): void
    {
        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([]);

        $result = $this->poller->poll($this->state, $this->client);

        self::assertNull($result);
        self::assertSame(0, $this->state->runtimePollErrorCount);
        self::assertSame('', $this->state->lastRuntimePollError);
    }

    public function testPollProcessesEventAndAdvancesSeq(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnStarted->value,
            runId: 'test-run',
            seq: 5,
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([$event]);

        $this->projector->expects(self::once())
            ->method('accept')
            ->with($event->toArray());

        $this->projector->expects(self::once())
            ->method('blocks')
            ->willReturn([]);

        $result = $this->poller->poll($this->state, $this->client);

        // Empty projected blocks returns empty array
        self::assertSame([], $result);
        self::assertSame(5, $this->state->lastSeq);
    }

    public function testPollDeduplicatesBySeq(): void
    {
        $this->state->lastSeq = 10;

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnStarted->value,
            runId: 'test-run',
            seq: 5, // <= lastSeq, should be skipped
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([$event]);

        $this->projector->expects(self::never())
            ->method('accept');

        $result = $this->poller->poll($this->state, $this->client);

        self::assertNull($result);
        self::assertSame(10, $this->state->lastSeq); // Not advanced
    }

    public function testSeqZeroEventsAlwaysPassThrough(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::AssistantTextDelta->value,
            runId: 'test-run',
            seq: 0, // Streaming event, not deduplicated
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([$event]);

        $this->projector->expects(self::once())
            ->method('accept');

        $this->projector->expects(self::once())
            ->method('blocks')
            ->willReturn([]);

        $result = $this->poller->poll($this->state, $this->client);

        // Empty projected blocks returns empty array
        self::assertSame([], $result);
    }

    public function testTurnStartedResetsUsage(): void
    {
        // Pre-set some usage values
        $this->state->usage->turnOutputTokens = 500;
        $this->state->usage->llmEndTime = 100.0;
        $this->state->usage->latestInputTokens = 200;

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnStarted->value,
            runId: 'test-run',
            seq: 1,
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([$event]);

        $this->projector->expects(self::once())
            ->method('accept');

        $this->projector->expects(self::once())
            ->method('blocks')
            ->willReturn([]);

        $this->poller->poll($this->state, $this->client);

        // Per-turn fields should be reset
        self::assertSame(0, $this->state->usage->turnOutputTokens);
        self::assertSame(0.0, $this->state->usage->llmEndTime);
        // latestInputTokens must be preserved across turns so the context
        // window % footer does not flicker to 0% during Working/streaming.
        self::assertSame(200, $this->state->usage->latestInputTokens);
    }

    public function testAssistantMessageCompletedAccumulatesUsage(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::AssistantMessageCompleted->value,
            runId: 'test-run',
            seq: 5,
            payload: [
                'usage' => [
                    'input_tokens' => 150,
                    'output_tokens' => 75,
                    'cost' => 0.003,
                ],
            ],
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([$event]);

        $this->projector->expects(self::once())
            ->method('accept');

        $this->projector->expects(self::once())
            ->method('blocks')
            ->willReturn([]);

        $this->poller->poll($this->state, $this->client);

        self::assertSame(150, $this->state->usage->inputTokens);
        self::assertSame(75, $this->state->usage->outputTokens);
        self::assertSame(75, $this->state->usage->turnOutputTokens);
        self::assertEqualsWithDelta(0.003, $this->state->usage->totalCost, 0.00001);
    }

    public function testActivityTransitionsOnEvent(): void
    {
        // Start with Starting
        $this->state->activity = RunActivityStateEnum::Starting;

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnStarted->value,
            runId: 'test-run',
            seq: 1,
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([$event]);

        $this->projector->expects(self::once())
            ->method('accept');

        $this->projector->expects(self::once())
            ->method('blocks')
            ->willReturn([]);

        $this->poller->poll($this->state, $this->client);

        // TurnStarted should transition to Running
        self::assertSame(RunActivityStateEnum::Running, $this->state->activity);
    }

    public function testPollHandlesExceptionWithTransientRetry(): void
    {
        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willThrowException(new \RuntimeException('Connection timeout'));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('RuntimeEventPoller polling error', self::anything());

        $result = $this->poller->poll($this->state, $this->client);

        self::assertNull($result); // Transient retry
        self::assertSame(1, $this->state->runtimePollErrorCount);
        self::assertStringContainsString('Connection timeout', $this->state->lastRuntimePollError);
    }

    public function testPollHandlesFatalErrorWithErrorBlock(): void
    {
        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willThrowException(new \RuntimeException('pipe broken')); // "pipe" is in fatal list

        $this->logger->expects(self::once())
            ->method('warning');

        $result = $this->poller->poll($this->state, $this->client);

        // Should return an error block (fatal on first hit since fatal errors skip retry)
        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame(RunActivityStateEnum::Failed, $this->state->activity);
        self::assertStringContainsString('Runtime transport error', $result[0]->text);
    }

    public function testPollHandlesControllerRestartLimitWithFailedStateAndPollError(): void
    {
        $message = 'Controller process has crashed too many times (3 restarts in 60s).';

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willThrowException(new \RuntimeException($message));

        $this->logger->expects(self::once())
            ->method('warning');

        $handleBefore = $this->state->handle;

        $result = $this->poller->poll($this->state, $this->client);

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame(RunActivityStateEnum::Failed, $this->state->activity);
        self::assertSame($handleBefore, $this->state->handle);
        self::assertSame($message, $this->state->lastRuntimePollError);
        self::assertStringContainsString('Runtime transport error', $result[0]->text);
        self::assertStringContainsString('crashed too many times', $result[0]->text);
    }

    public function testErrorCountResetOnSuccessfulPoll(): void
    {
        $this->state->runtimePollErrorCount = 3;

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([]);

        $this->poller->poll($this->state, $this->client);

        self::assertSame(0, $this->state->runtimePollErrorCount);
        self::assertSame('', $this->state->lastRuntimePollError);
    }

    public function testOnToolTerminalCallbackFiresForCompleted(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionCompleted->value,
            runId: 'test-run',
            seq: 5,
            payload: ['tool_call_id' => 'tc-123', 'is_error' => false],
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([$event]);

        $this->projector->method('accept');
        $this->projector->method('blocks')->willReturn([]);

        $called = null;
        $callback = static function (RuntimeEvent $e) use (&$called): void {
            $called = $e;
        };

        $this->poller->poll($this->state, $this->client, onToolTerminal: $callback);

        self::assertNotNull($called);
        self::assertSame(RuntimeEventTypeEnum::ToolExecutionCompleted->value, $called->type);
        self::assertSame('tc-123', $called->payload['tool_call_id'] ?? null);
    }

    public function testOnToolTerminalCallbackFiresForFailed(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionFailed->value,
            runId: 'test-run',
            seq: 6,
            payload: ['tool_call_id' => 'tc-456', 'is_error' => true],
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([$event]);

        $this->projector->method('accept');
        $this->projector->method('blocks')->willReturn([]);

        $called = null;
        $callback = static function (RuntimeEvent $e) use (&$called): void {
            $called = $e;
        };

        $this->poller->poll($this->state, $this->client, onToolTerminal: $callback);

        self::assertNotNull($called);
        self::assertSame(RuntimeEventTypeEnum::ToolExecutionFailed->value, $called->type);
        self::assertSame('tc-456', $called->payload['tool_call_id'] ?? null);
    }

    public function testQueuedFollowUpDispatchedOnRunCancelled(): void
    {
        $this->state->queuedFollowUp = 'Continue after cancel';
        $this->state->activity = RunActivityStateEnum::Cancelling;

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunCancelled->value,
            runId: 'test-run',
            seq: 10,
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([$event]);

        // Expect the client to receive a follow_up command with the queued text
        $this->client->expects(self::once())
            ->method('send')
            ->with(
                'test-run',
                self::callback(static fn ($cmd): bool =>
                    $cmd instanceof UserCommand
                    && 'follow_up' === $cmd->type
                    && 'Continue after cancel' === $cmd->text
                ),
            );

        $this->projector->method('accept');
        $this->projector->method('blocks')->willReturn([]);

        $this->poller->poll($this->state, $this->client);

        // Queued text should be cleared after dispatch
        self::assertNull($this->state->queuedFollowUp);
        // Activity should transition to Cancelled (from RunCancelled event),
        // then to Starting (from the follow_up dispatch)
        self::assertSame(RunActivityStateEnum::Starting, $this->state->activity);
    }


    public function testCancellingClearsToCancelledOnRunCancelledWithoutQueuedFollowUp(): void
    {
        $this->state->activity = RunActivityStateEnum::Cancelling;

        $events = [
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::ToolExecutionFailed->value,
                runId: 'test-run',
                seq: 128,
                payload: ['tool_call_id' => 'call_1', 'is_error' => true],
            ),
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::RunCancelled->value,
                runId: 'test-run',
                seq: 132,
                payload: ['reason' => 'cancelled'],
            ),
        ];

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn($events);

        $this->client->expects(self::never())->method('send');

        $this->projector->method('accept');
        $this->projector->method('blocks')->willReturn([]);

        $this->poller->poll($this->state, $this->client);

        self::assertSame(RunActivityStateEnum::Cancelled, $this->state->activity);
        self::assertFalse($this->state->activity->isActive());
    }

    public function testCancellingClearsToCancelledOnToolExecutionCancelledWithoutRunCancelled(): void
    {
        $this->state->activity = RunActivityStateEnum::Cancelling;

        $events = [
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::ToolExecutionCancelled->value,
                runId: 'test-run',
                seq: 128,
                payload: [
                    'tool_call_id' => 'call_1',
                    'is_error' => true,
                    'result' => 'Tool execution cancelled by user.',
                ],
            ),
        ];

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn($events);

        $this->client->expects(self::never())->method('send');

        $this->projector->method('accept');
        $this->projector->method('blocks')->willReturn([]);

        $this->poller->poll($this->state, $this->client);

        self::assertSame(RunActivityStateEnum::Cancelled, $this->state->activity);
        self::assertFalse($this->state->activity->isActive());
    }

    public function testQueuedFollowUpNotDispatchedWithoutRunCancelled(): void
    {
        $this->state->queuedFollowUp = 'Waiting message';

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnStarted->value,
            runId: 'test-run',
            seq: 10,
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([$event]);

        $this->client->expects(self::never())
            ->method('send');

        $this->projector->method('accept');
        $this->projector->method('blocks')->willReturn([]);

        $this->poller->poll($this->state, $this->client);

        // Queued text should persist — only cleared on RunCancelled
        self::assertNotNull($this->state->queuedFollowUp);
        self::assertSame('Waiting message', $this->state->queuedFollowUp);
    }

    public function testOnToolTerminalNotCalledForNonTerminalEvents(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionStarted->value,
            runId: 'test-run',
            seq: 7,
            payload: ['tool_call_id' => 'tc-789'],
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([$event]);

        $this->projector->method('accept');
        $this->projector->method('blocks')->willReturn([]);

        $called = false;
        $callback = static function (RuntimeEvent $e) use (&$called): void {
            $called = true;
        };

        $this->poller->poll($this->state, $this->client, onToolTerminal: $callback);

        self::assertFalse($called);
    }

    /**
     * When activity is Cancelling and a queued follow-up exists,
     * CompactionCompleted must NOT dispatch the follow-up — it
     * belongs to the RunCancelled branch after cancellation
     * terminalizes.
     */
    public function testQueuedFollowUpNotDispatchedOnCompactionCompletedWhileCancelling(): void
    {
        $this->state->queuedFollowUp = 'Continue after cancel';
        $this->state->activity = RunActivityStateEnum::Cancelling;

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionCompleted->value,
            runId: 'test-run',
            seq: 10,
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([$event]);

        // Must NOT dispatch — send() should not be called
        $this->client->expects(self::never())
            ->method('send');

        $this->projector->method('accept');
        $this->projector->method('blocks')->willReturn([]);

        $this->poller->poll($this->state, $this->client);

        // Queued text must survive for the RunCancelled branch
        self::assertNotNull($this->state->queuedFollowUp);
        self::assertSame('Continue after cancel', $this->state->queuedFollowUp);
        // Activity stays Cancelling (not overwritten to Starting)
        self::assertSame(RunActivityStateEnum::Cancelling, $this->state->activity);
    }

    /**
     * When activity is Cancelling and a queued follow-up exists,
     * CompactionFailed must NOT dispatch the follow-up — same
     * guard as CompactionCompleted.
     */
    public function testQueuedFollowUpNotDispatchedOnCompactionFailedWhileCancelling(): void
    {
        $this->state->queuedFollowUp = 'Resume after failed compact';
        $this->state->activity = RunActivityStateEnum::Cancelling;

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::CompactionFailed->value,
            runId: 'test-run',
            seq: 11,
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([$event]);

        $this->client->expects(self::never())
            ->method('send');

        $this->projector->method('accept');
        $this->projector->method('blocks')->willReturn([]);

        $this->poller->poll($this->state, $this->client);

        self::assertNotNull($this->state->queuedFollowUp);
        self::assertSame('Resume after failed compact', $this->state->queuedFollowUp);
        self::assertSame(RunActivityStateEnum::Cancelling, $this->state->activity);
    }

    public function testPollContinuesAfterToolQuestionCallbackThrows(): void
    {
        $toolQuestion = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolQuestionRequested->value,
            runId: 'test-run',
            seq: 10,
            payload: ['request_id' => 'bash_bg_x', 'kind' => 'confirm', 'schema' => null],
        );
        $cancelled = new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunCancelled->value,
            runId: 'test-run',
            seq: 11,
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([$toolQuestion, $cancelled]);

        $this->projector->expects(self::exactly(2))
            ->method('accept');

        $this->projector->expects(self::once())
            ->method('blocks')
            ->willReturn([]);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'RuntimeEventPoller event callback failed',
                self::callback(static function (array $context): bool {
                    return 'onToolQuestionRequested' === ($context['callback'] ?? null)
                        && RuntimeEventTypeEnum::ToolQuestionRequested->value === ($context['runtime_event_type'] ?? null);
                }),
            );

        $this->poller->poll(
            $this->state,
            $this->client,
            onToolQuestionRequested: static function (): void {
                throw new \RuntimeException('simulated overlay failure');
            },
        );

        self::assertSame(11, $this->state->lastSeq);
        self::assertSame(RunActivityStateEnum::Cancelled, $this->state->activity);
    }

    public function testPollWholesaleReplacesTranscriptOnRunLeafChanged(): void
    {
        // Thesis: after a RunLeafChanged event, the poller fetches active-path
        // RuntimeEvents from the provider, replays them through the projector,
        // and wholesale-replaces $state->transcript. Old abandoned-branch blocks
        // must be gone, activity = Idle, queuedFollowUp = null, lastSeq = LeafSet seq.

        // Pre-populate transcript with old abandoned-branch blocks
        $this->state->transcript = [
            new TranscriptBlock(
                id: 'old-branch-block-1',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: 'test-run',
                seq: 10,
                text: 'Old abandoned branch content',
            ),
        ];

        // Mock turn tree provider to return active-path RuntimeEvents
        $turnTreeProvider = $this->createMock(TurnTreeProviderInterface::class);
        $activeEvents = [
            new RuntimeEvent(
                type: RuntimeEventTypeEnum::AssistantMessageCompleted->value,
                runId: 'test-run',
                seq: 35,
                payload: ['text' => 'New active path response'],
            ),
        ];
        $turnTreeProvider->expects(self::once())
            ->method('activePathRuntimeEvents')
            ->with('test-run', 3)
            ->willReturn($activeEvents);

        // Real projector: tracks accepted events, returns TranscriptBlocks
        $projector = new class implements TranscriptProjectorInterface {
            /** @var list<array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int}> */
            public array $accepted = [];

            public function accept(array $event): void
            {
                $this->accepted[] = $event;
            }

            public function blocks(): array
            {
                $blocks = [];
                foreach ($this->accepted as $e) {
                    $blocks[] = new TranscriptBlock(
                        id: 'block-seq-'.$e['seq'],
                        kind: TranscriptBlockKindEnum::AssistantMessage,
                        runId: 'test-run',
                        seq: $e['seq'],
                        text: (string) ($e['payload']['text'] ?? ''),
                    );
                }

                return $blocks;
            }

            public function reset(): void
            {
                $this->accepted = [];
            }
        };

        $eventApplier = new TuiRuntimeEventApplier($projector);
        $poller = new RuntimeEventPoller(
            $eventApplier,
            $this->logger,
            new RuntimeExceptionBoundary(
                $this->createStub(EventDispatcherInterface::class),
            ),
            $turnTreeProvider,
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::RunLeafChanged->value,
                    runId: 'test-run',
                    seq: 20,
                    payload: ['turn_no' => 3],
                ),
            ]);

        $result = $poller->poll($this->state, $this->client);

        // Transcript wholesale replaced (old block gone, new block present)
        self::assertNotNull($result);
        self::assertCount(1, $result);
        self::assertSame($result, $this->state->transcript);
        self::assertSame('block-seq-35', $result[0]->id);
        self::assertSame('New active path response', $result[0]->text);
        self::assertCount(1, $this->state->transcript, 'Old abandoned-branch block must be gone');

        // Activity becomes Idle after RunLeafChanged
        self::assertSame(RunActivityStateEnum::Idle, $this->state->activity);

        // queuedFollowUp cleared
        self::assertNull($this->state->queuedFollowUp);

        // lastSeq advanced to RunLeafChanged seq (not moved backward by rebuild)
        self::assertSame(20, $this->state->lastSeq);
    }

    public function testPollGracefullyDegradesOnLeafChangeRebuildFailure(): void
    {
        // Thesis: when activePathRuntimeEvents throws, the poller catches the
        // exception, logs a structured warning, clears the transcript (so stale
        // abandoned-branch blocks are not shown), and does not crash.

        // Pre-populate transcript with old blocks
        $this->state->transcript = [
            new TranscriptBlock(
                id: 'old-block',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: 'test-run',
                seq: 10,
                text: 'Stale abandoned block',
            ),
        ];

        // Provider throws on rebuild
        $turnTreeProvider = $this->createMock(TurnTreeProviderInterface::class);
        $turnTreeProvider->method('activePathRuntimeEvents')
            ->willThrowException(new \RuntimeException('Events file not found'));

        // Stub projector (never reached, but required by TuiRuntimeEventApplier)
        $projector = new class implements TranscriptProjectorInterface {
            public function accept(array $event): void {}
            public function blocks(): array { return []; }
            public function reset(): void {}
        };

        $eventApplier = new TuiRuntimeEventApplier($projector);
        $poller = new RuntimeEventPoller(
            $eventApplier,
            $this->logger,
            new RuntimeExceptionBoundary(
                $this->createStub(EventDispatcherInterface::class),
            ),
            $turnTreeProvider,
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::RunLeafChanged->value,
                    runId: 'test-run',
                    seq: 20,
                    payload: ['turn_no' => 3],
                ),
            ]);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('runtime_event_poller.leaf_changed_rebuild_failed', self::anything());

        $result = $poller->poll($this->state, $this->client);

        // Transcript cleared on failure — stale blocks must not linger
        self::assertSame([], $this->state->transcript, 'Transcript must be empty on rebuild failure');

        // Poll returns the (empty) transcript so the renderer redraws as blank
        self::assertSame([], $result);

        // lastSeq still advanced to the RunLeafChanged seq
        self::assertSame(20, $this->state->lastSeq);
    }

    /**
     * C1: Malformed RunLeafChanged (missing/zero turn_no) must clear the transcript
     * and log a structured warning, not silently leave stale abandoned-branch blocks.
     */
    public function testPollHandlesMalformedRunLeafChanged(): void
    {
        // Thesis: a RunLeafChanged with missing/0 turn_no is malformed; the poller
        // must clear the transcript, log a structured warning, and continue without
        // crashing rather than leaving stale abandoned-branch blocks.

        // Pre-populate transcript with stale blocks that MUST be cleared
        $this->state->transcript = [
            new TranscriptBlock(
                id: 'stale-block',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: 'test-run',
                seq: 10,
                text: 'Stale abandoned branch block',
            ),
        ];

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::RunLeafChanged->value,
                    runId: 'test-run',
                    seq: 20,
                    payload: [], // no turn_no — malformed
                ),
            ]);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('runtime_event_poller.leaf_changed_malformed', self::anything());

        $this->projector->method('accept');
        $this->projector->method('blocks')->willReturn([]);

        $result = $this->poller->poll($this->state, $this->client);

        // Stale blocks must be removed
        self::assertSame([], $this->state->transcript, 'Transcript must be empty after malformed RunLeafChanged');
        self::assertSame([], $result);

        // lastSeq still advanced
        self::assertSame(20, $this->state->lastSeq);
    }

    /**
     * C2: RunLeafChanged followed by a normal event in the same poll batch must
     * include the post-leaf block in the returned result (not silently dropped).
     */
    public function testPollSyncsPostLeafEventsAfterRunLeafChanged(): void
    {
        // Thesis: after a RunLeafChanged triggers a wholesale transcript rebuild,
        // any normal event processed later in the same batch must be synchronised
        // into $state->transcript via synchronizeProjectedBlocks, not lost.

        // Real projector that tracks accepted events and returns blocks
        $projector = new class implements TranscriptProjectorInterface {
            /** @var list<array{type: string, seq: int, payload?: array}> */
            public array $accepted = [];
            public bool $wasReset = false;

            public function accept(array $event): void
            {
                $this->accepted[] = $event;
            }

            public function blocks(): array
            {
                $blocks = [];
                foreach ($this->accepted as $e) {
                    $blocks[] = new TranscriptBlock(
                        id: 'block-seq-'.$e['seq'],
                        kind: TranscriptBlockKindEnum::AssistantMessage,
                        runId: 'test-run',
                        seq: $e['seq'],
                        text: (string) ($e['payload']['text'] ?? ''),
                    );
                }

                return $blocks;
            }

            public function reset(): void
            {
                $this->accepted = [];
                $this->wasReset = true;
            }
        };

        $turnTreeProvider = $this->createMock(TurnTreeProviderInterface::class);
        $turnTreeProvider->expects(self::once())
            ->method('activePathRuntimeEvents')
            ->with('test-run', 2)
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::AssistantMessageCompleted->value,
                    runId: 'test-run',
                    seq: 30,
                    payload: ['text' => 'Rebuilt active path block'],
                ),
            ]);

        $eventApplier = new TuiRuntimeEventApplier($projector);
        $poller = new RuntimeEventPoller(
            $eventApplier,
            $this->logger,
            new RuntimeExceptionBoundary(
                $this->createStub(EventDispatcherInterface::class),
            ),
            $turnTreeProvider,
        );

        $this->client->expects(self::once())
            ->method('events')
            ->with('test-run')
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::RunLeafChanged->value,
                    runId: 'test-run',
                    seq: 20,
                    payload: ['turn_no' => 2],
                ),
                // Normal event arriving in the same batch after RunLeafChanged
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::AssistantMessageCompleted->value,
                    runId: 'test-run',
                    seq: 35,
                    payload: ['text' => 'Post-leaf event'],
                ),
            ]);

        $result = $poller->poll($this->state, $this->client);

        // Both the rebuilt active-path block AND the post-leaf block must appear
        self::assertNotNull($result, 'Result must not be null when events were processed');
        self::assertCount(2, $result, 'Both rebuilt and post-leaf blocks must be present');
        self::assertSame('block-seq-30', $result[0]->id);
        self::assertSame('Rebuilt active path block', $result[0]->text);
        self::assertSame('block-seq-35', $result[1]->id);
        self::assertSame('Post-leaf event', $result[1]->text);

        // lastSeq advanced to the highest seq in the batch (35, not 20)
        self::assertSame(35, $this->state->lastSeq);
    }

}
