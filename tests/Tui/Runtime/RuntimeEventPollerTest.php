<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeTransportException;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
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
    private LoggerInterface $logger;
    private SessionTranscriptProviderInterface&MockObject $sessionTranscriptProvider;
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
        $this->projector->method('accept');
        $this->projector->method('reset');
        $this->projector->method('blocks')->willReturn([]);
        $this->projector->method('drainChanges')->willReturn(TranscriptChangeSet::incremental([]));
        $this->projector->method('replaceProjectedBlocks');
        $this->sessionTranscriptProvider = $this->createMock(SessionTranscriptProviderInterface::class);
        $this->sessionTranscriptProvider->method('transcriptAtPosition')->willReturn(new SessionTranscriptSnapshotDTO([], []));
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier($this->projector, SubagentProgressSerializerTestSupport::denormalizer()),
            $this->logger,
            new RuntimeExceptionBoundary(
                $this->createStub(EventDispatcherInterface::class),
            ),
            $this->sessionTranscriptProvider,
        );
    }

    public function testPollReturnsNullWhenNoRunHandle(): void
    {
        $state = new TuiSessionState('test-session');
        $result = $this->poller->poll($state, $this->client);

        $this->assertNull($result);
    }

    public function testPollReturnsNullForEmptyEvents(): void
    {
        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([]);

        $result = $this->poller->poll($this->state, $this->client);

        $this->assertNull($result);
        $this->assertSame(0, $this->state->runtimePollErrorCount);
        $this->assertSame('', $this->state->lastRuntimePollError);
    }

    public function testPollPassesLastSuccessfullyAppliedSequenceToFreshClientDrain(): void
    {
        $this->state->lastSeq = 7;
        $event = new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, 'test-run', 8);

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', 7)
            ->willReturn([$event]);
        $this->projector->expects($this->once())->method('accept')->with($event);
        $this->projector->expects($this->once())->method('drainChanges')->willReturn(TranscriptChangeSet::incremental([]));

        $this->poller->poll($this->state, $this->client);

        $this->assertSame(8, $this->state->lastSeq);
    }

    public function testPollProcessesEventAndAdvancesSeq(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnStarted->value,
            runId: 'test-run',
            seq: 5,
        );

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$event]);

        $this->projector->expects($this->once())
            ->method('accept')
            ->with($event);

        $this->projector->expects($this->once())
            ->method('drainChanges')
            ->willReturn(TranscriptChangeSet::incremental([]));

        $result = $this->poller->poll($this->state, $this->client);

        // Empty projected changes returns null (nothing to paint)
        $this->assertNull($result);
        $this->assertSame(5, $this->state->lastSeq);
    }

    public function testPollDeduplicatesBySeq(): void
    {
        $this->state->lastSeq = 10;

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnStarted->value,
            runId: 'test-run',
            seq: 5, // <= lastSeq, should be skipped
        );

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$event]);

        $this->projector->expects($this->never())
            ->method('accept');

        $result = $this->poller->poll($this->state, $this->client);

        $this->assertNull($result);
        $this->assertSame(10, $this->state->lastSeq); // Not advanced
    }

    public function testSeqZeroEventsAlwaysPassThrough(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::AssistantTextDelta->value,
            runId: 'test-run',
            seq: 0, // Streaming event, not deduplicated
        );

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$event]);

        $this->projector->expects($this->once())
            ->method('accept');

        $this->projector->expects($this->once())
            ->method('drainChanges')
            ->willReturn(TranscriptChangeSet::incremental([]));

        $result = $this->poller->poll($this->state, $this->client);

        // Empty projected changes returns null (nothing to paint)
        $this->assertNull($result);
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

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$event]);

        $this->projector->expects($this->once())
            ->method('accept');

        $this->projector->expects($this->once())
            ->method('drainChanges')
            ->willReturn(TranscriptChangeSet::incremental([]));

        $this->poller->poll($this->state, $this->client);

        // Per-turn fields should be reset
        $this->assertSame(0, $this->state->usage->turnOutputTokens);
        $this->assertSame(0.0, $this->state->usage->llmEndTime);
        // latestInputTokens must be preserved across turns so the context
        // window % footer does not flicker to 0% during Working/streaming.
        $this->assertSame(200, $this->state->usage->latestInputTokens);
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

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$event]);

        $this->projector->expects($this->once())
            ->method('accept');

        $this->projector->expects($this->once())
            ->method('drainChanges')
            ->willReturn(TranscriptChangeSet::incremental([]));

        $this->poller->poll($this->state, $this->client);

        $this->assertSame(150, $this->state->usage->inputTokens);
        $this->assertSame(75, $this->state->usage->outputTokens);
        $this->assertSame(75, $this->state->usage->turnOutputTokens);
        $this->assertEqualsWithDelta(0.003, $this->state->usage->totalCost, 0.00001);
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

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$event]);

        $this->projector->expects($this->once())
            ->method('accept');

        $this->projector->expects($this->once())
            ->method('drainChanges')
            ->willReturn(TranscriptChangeSet::incremental([]));

        $this->poller->poll($this->state, $this->client);

        // TurnStarted should transition to Running
        $this->assertSame(RunActivityStateEnum::Running, $this->state->activity);
    }

    public function testPollHandlesExceptionWithTransientRetry(): void
    {
        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willThrowException(new \RuntimeException('Connection timeout'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('RuntimeEventPoller polling error', $this->anything());

        $result = $this->poller->poll($this->state, $this->client);

        $this->assertNull($result); // Transient retry
        $this->assertSame(1, $this->state->runtimePollErrorCount);
        $this->assertStringContainsString('Connection timeout', $this->state->lastRuntimePollError);
    }

    public function testPollHandlesFatalTypedTransportErrorWithErrorBlock(): void
    {
        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willThrowException(new RuntimeTransportException('pipe broken'));

        $this->logger->expects($this->once())
            ->method('warning');

        $result = $this->poller->poll($this->state, $this->client);

        // Typed transport failures are fatal on first hit (skip retry).
        $this->assertInstanceOf(TranscriptChangeSet::class, $result);
        $this->assertCount(1, $result->upserts);
        $this->assertSame(RunActivityStateEnum::Failed, $this->state->activity);
        $this->assertStringContainsString('Runtime transport error', $result->upserts[0]->text);
    }

    public function testPlainRuntimeExceptionWithTransportKeywordsStaysRetryable(): void
    {
        // Regression: the old message-substring fatal classification would
        // treat any RuntimeException mentioning process/pipe/closed as fatal.
        // Only the typed RuntimeTransportException is fatal now.
        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willThrowException(new \RuntimeException('process pipe closed while reading events'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('RuntimeEventPoller polling error', $this->anything());

        $result = $this->poller->poll($this->state, $this->client);

        $this->assertNull($result); // Transient retry
        $this->assertSame(1, $this->state->runtimePollErrorCount);
        $this->assertSame(RunActivityStateEnum::Idle, $this->state->activity);
    }

    public function testPollHandlesControllerRestartLimitWithFailedStateAndPollError(): void
    {
        $message = 'Controller process has crashed too many times (3 restarts in 60s).';

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willThrowException(new RuntimeTransportException($message));

        $this->logger->expects($this->once())
            ->method('warning');

        $handleBefore = $this->state->handle;

        $result = $this->poller->poll($this->state, $this->client);

        $this->assertInstanceOf(TranscriptChangeSet::class, $result);
        $this->assertCount(1, $result->upserts);
        $this->assertSame(RunActivityStateEnum::Failed, $this->state->activity);
        $this->assertSame($handleBefore, $this->state->handle);
        $this->assertSame($message, $this->state->lastRuntimePollError);
        $this->assertStringContainsString('Runtime transport error', $result->upserts[0]->text);
        $this->assertStringContainsString('crashed too many times', $result->upserts[0]->text);
    }

    public function testErrorCountResetOnSuccessfulPoll(): void
    {
        $this->state->runtimePollErrorCount = 3;

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([]);

        $this->poller->poll($this->state, $this->client);

        $this->assertSame(0, $this->state->runtimePollErrorCount);
        $this->assertSame('', $this->state->lastRuntimePollError);
    }

    public function testOnToolTerminalCallbackFiresForCompleted(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionCompleted->value,
            runId: 'test-run',
            seq: 5,
            payload: ['tool_call_id' => 'tc-123', 'is_error' => false],
        );

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$event]);

        $this->projector->method('accept');
        $this->projector->method('drainChanges')->willReturn(TranscriptChangeSet::incremental([]));

        $called = null;
        $callback = static function (RuntimeEvent $e) use (&$called): void {
            $called = $e;
        };

        $this->poller->poll($this->state, $this->client, onToolTerminal: $callback);

        $this->assertNotNull($called);
        $this->assertSame(RuntimeEventTypeEnum::ToolExecutionCompleted->value, $called->type);
        $this->assertSame('tc-123', $called->payload['tool_call_id'] ?? null);
    }

    public function testOnToolTerminalCallbackFiresForFailed(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionFailed->value,
            runId: 'test-run',
            seq: 6,
            payload: ['tool_call_id' => 'tc-456', 'is_error' => true],
        );

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$event]);

        $this->projector->method('accept');
        $this->projector->method('drainChanges')->willReturn(TranscriptChangeSet::incremental([]));

        $called = null;
        $callback = static function (RuntimeEvent $e) use (&$called): void {
            $called = $e;
        };

        $this->poller->poll($this->state, $this->client, onToolTerminal: $callback);

        $this->assertNotNull($called);
        $this->assertSame(RuntimeEventTypeEnum::ToolExecutionFailed->value, $called->type);
        $this->assertSame('tc-456', $called->payload['tool_call_id'] ?? null);
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

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$event]);

        // Expect the client to receive a follow_up command with the queued text
        $this->client->expects($this->once())
            ->method('send')
            ->with(
                'test-run',
                $this->callback(static fn ($cmd): bool => $cmd instanceof UserCommand
                    && 'follow_up' === $cmd->type
                    && 'Continue after cancel' === $cmd->text
                ),
            );

        $this->projector->method('accept');
        $this->projector->method('drainChanges')->willReturn(TranscriptChangeSet::incremental([]));

        $this->poller->poll($this->state, $this->client);

        // Queued text should be cleared after dispatch
        $this->assertNull($this->state->queuedFollowUp);
        // Activity should transition to Cancelled (from RunCancelled event),
        // then to Starting (from the follow_up dispatch)
        $this->assertSame(RunActivityStateEnum::Starting, $this->state->activity);
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

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn($events);

        $this->client->expects($this->never())->method('send');

        $this->projector->method('accept');
        $this->projector->method('drainChanges')->willReturn(TranscriptChangeSet::incremental([]));

        $this->poller->poll($this->state, $this->client);

        $this->assertSame(RunActivityStateEnum::Cancelled, $this->state->activity);
        $this->assertFalse($this->state->activity->isActive());
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

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn($events);

        $this->client->expects($this->never())->method('send');

        $this->projector->method('accept');
        $this->projector->method('drainChanges')->willReturn(TranscriptChangeSet::incremental([]));

        $this->poller->poll($this->state, $this->client);

        $this->assertSame(RunActivityStateEnum::Cancelled, $this->state->activity);
        $this->assertFalse($this->state->activity->isActive());
    }

    public function testQueuedFollowUpNotDispatchedWithoutRunCancelled(): void
    {
        $this->state->queuedFollowUp = 'Waiting message';

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnStarted->value,
            runId: 'test-run',
            seq: 10,
        );

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$event]);

        $this->client->expects($this->never())
            ->method('send');

        $this->projector->method('accept');
        $this->projector->method('drainChanges')->willReturn(TranscriptChangeSet::incremental([]));

        $this->poller->poll($this->state, $this->client);

        // Queued text should persist — only cleared on RunCancelled
        $this->assertNotNull($this->state->queuedFollowUp);
        $this->assertSame('Waiting message', $this->state->queuedFollowUp);
    }

    public function testOnToolTerminalNotCalledForNonTerminalEvents(): void
    {
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionStarted->value,
            runId: 'test-run',
            seq: 7,
            payload: ['tool_call_id' => 'tc-789'],
        );

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$event]);

        $this->projector->method('accept');
        $this->projector->method('drainChanges')->willReturn(TranscriptChangeSet::incremental([]));

        $called = false;
        $callback = static function (RuntimeEvent $e) use (&$called): void {
            $called = true;
        };

        $this->poller->poll($this->state, $this->client, onToolTerminal: $callback);

        $this->assertFalse($called);
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

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$event]);

        // Must NOT dispatch — send() should not be called
        $this->client->expects($this->never())
            ->method('send');

        $this->projector->method('accept');
        $this->projector->method('drainChanges')->willReturn(TranscriptChangeSet::incremental([]));

        $this->poller->poll($this->state, $this->client);

        // Queued text must survive for the RunCancelled branch
        $this->assertNotNull($this->state->queuedFollowUp);
        $this->assertSame('Continue after cancel', $this->state->queuedFollowUp);
        // Activity stays Cancelling (not overwritten to Starting)
        $this->assertSame(RunActivityStateEnum::Cancelling, $this->state->activity);
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

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$event]);

        $this->client->expects($this->never())
            ->method('send');

        $this->projector->method('accept');
        $this->projector->method('drainChanges')->willReturn(TranscriptChangeSet::incremental([]));

        $this->poller->poll($this->state, $this->client);

        $this->assertNotNull($this->state->queuedFollowUp);
        $this->assertSame('Resume after failed compact', $this->state->queuedFollowUp);
        $this->assertSame(RunActivityStateEnum::Cancelling, $this->state->activity);
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

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$toolQuestion, $cancelled]);

        $this->projector->expects($this->exactly(2))
            ->method('accept');

        $this->projector->expects($this->once())
            ->method('drainChanges')
            ->willReturn(TranscriptChangeSet::incremental([]));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'RuntimeEventPoller event callback failed',
                $this->callback(static function (array $context): bool {
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

        $this->assertSame(11, $this->state->lastSeq);
        $this->assertSame(RunActivityStateEnum::Cancelled, $this->state->activity);
    }

    public function testPollWholesaleReplacesTranscriptOnRunHistoryPositionChanged(): void
    {
        // Thesis: after a RunHistoryPositionChanged event, the poller fetches retained-history
        // RuntimeEvents from the provider, replays them through the projector,
        // and wholesale-replaces $state->transcript. Old discarded-tail blocks
        // must be gone, activity = Idle, queuedFollowUp = null, lastSeq = history_position_set seq.

        $this->state->queuedUserMessages = ['ik-abandoned' => 'Want to test bash in parallel'];

        // Pre-populate transcript with old discarded-tail blocks
        $this->state->transcript = [
            new TranscriptBlock(
                id: 'old-discarded-block-1',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: 'test-run',
                seq: 10,
                text: 'Old discarded-tail content',
            ),
        ];

        // Mock history/transcript provider to return retained-history RuntimeEvents
        $sessionTranscriptProvider = $this->createMock(SessionTranscriptProviderInterface::class);
        $rebuiltBlocks = [
            new TranscriptBlock(
                id: 'block-seq-35',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: 'test-run',
                seq: 35,
                text: 'New retained history response',
            ),
        ];
        $sessionTranscriptProvider->expects($this->once())
            ->method('transcriptAtPosition')
            ->with('test-run', 3)
            ->willReturn(new SessionTranscriptSnapshotDTO($rebuiltBlocks, []));

        $eventApplier = new TuiRuntimeEventApplier($this->projector, SubagentProgressSerializerTestSupport::denormalizer());
        $poller = new RuntimeEventPoller(
            $eventApplier,
            $this->logger,
            new RuntimeExceptionBoundary(
                $this->createStub(EventDispatcherInterface::class),
            ),
            $sessionTranscriptProvider,
        );

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::RunHistoryPositionChanged->value,
                    runId: 'test-run',
                    seq: 20,
                    payload: ['position_turn_no' => 3],
                ),
            ]);

        $result = $poller->poll($this->state, $this->client);

        // Transcript wholesale replaced (old block gone, new block present)
        $this->assertInstanceOf(TranscriptChangeSet::class, $result);
        $this->assertTrue($result->isFull());
        $this->assertCount(1, $result->blocks());
        $this->assertSame($result->blocks(), $this->state->transcript);
        $this->assertSame('block-seq-35', $result->blocks()[0]->id);
        $this->assertSame('New retained history response', $result->blocks()[0]->text);
        $this->assertCount(1, $this->state->transcript, 'Old discarded-tail block must be gone');

        // Activity becomes Idle after RunHistoryPositionChanged
        $this->assertSame(RunActivityStateEnum::Idle, $this->state->activity);

        // queuedFollowUp cleared
        $this->assertNull($this->state->queuedFollowUp);
        $this->assertSame([], $this->state->queuedUserMessages, 'Discarded-tail queued commands must not linger as ⏳');

        // lastSeq advanced to RunHistoryPositionChanged seq (not moved backward by rebuild)
        $this->assertSame(20, $this->state->lastSeq);
    }

    public function testPollGracefullyDegradesOnHistoryPositionChangeRebuildFailure(): void
    {
        // Thesis: when transcriptAtPosition throws, the poller catches the
        // exception, logs a structured warning, clears the transcript (so stale
        // discarded-tail blocks are not shown), and does not crash.

        // Pre-populate transcript with old blocks
        $this->state->transcript = [
            new TranscriptBlock(
                id: 'old-block',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: 'test-run',
                seq: 10,
                text: 'Stale discarded block',
            ),
        ];

        // Provider throws on rebuild
        $sessionTranscriptProvider = $this->createMock(SessionTranscriptProviderInterface::class);
        $sessionTranscriptProvider->method('transcriptAtPosition')
            ->willThrowException(new \RuntimeException('Events file not found'));

        $this->client->expects($this->once())
                    ->method('events')
                    ->with('test-run', $this->anything())
                    ->willReturn([
                        new RuntimeEvent(
                            type: RuntimeEventTypeEnum::RunHistoryPositionChanged->value,
                            runId: 'test-run',
                            seq: 20,
                            payload: ['position_turn_no' => 3],
                        ),
                    ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('runtime_event_poller.history_position_changed_rebuild_failed', $this->anything());
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier($this->projector, SubagentProgressSerializerTestSupport::denormalizer()),
            $logger,
            new RuntimeExceptionBoundary(
                $this->createStub(EventDispatcherInterface::class),
            ),
            $sessionTranscriptProvider,
        );

        $result = $poller->poll($this->state, $this->client);

        // Transcript cleared on failure — stale blocks must not linger
        $this->assertSame([], $this->state->transcript, 'Transcript must be empty on rebuild failure');

        // Poll returns the (empty) transcript so the renderer redraws as blank
        $this->assertInstanceOf(TranscriptChangeSet::class, $result);
        $this->assertTrue($result->isFull());
        $this->assertSame([], $result->blocks());

        // lastSeq still advanced to the RunHistoryPositionChanged seq
        $this->assertSame(20, $this->state->lastSeq);
    }

    /**
     * C1: Malformed RunHistoryPositionChanged (missing position_turn_no) must clear the transcript
     * and log a structured warning, not silently leave stale discarded-tail blocks.
     */
    public function testPollHandlesMalformedRunHistoryPositionChanged(): void
    {
        // Thesis: a RunHistoryPositionChanged with missing position_turn_no is malformed; the poller
        // must clear the transcript, log a structured warning, and continue without
        // crashing rather than leaving stale discarded-tail blocks.

        // Pre-populate transcript with stale blocks that MUST be cleared
        $this->state->transcript = [
            new TranscriptBlock(
                id: 'stale-block',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: 'test-run',
                seq: 10,
                text: 'Stale discarded-tail block',
            ),
        ];

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::RunHistoryPositionChanged->value,
                    runId: 'test-run',
                    seq: 20,
                    payload: [], // no position_turn_no — malformed
                ),
            ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('runtime_event_poller.history_position_changed_malformed', $this->anything());

        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier($this->projector, SubagentProgressSerializerTestSupport::denormalizer()),
            $logger,
            new RuntimeExceptionBoundary(
                $this->createStub(EventDispatcherInterface::class),
            ),
            $this->sessionTranscriptProvider,
        );

        $result = $poller->poll($this->state, $this->client);

        // Stale blocks must be removed
        $this->assertSame([], $this->state->transcript, 'Transcript must be empty after malformed RunHistoryPositionChanged');
        $this->assertInstanceOf(TranscriptChangeSet::class, $result);
        $this->assertTrue($result->isFull());
        $this->assertSame([], $result->blocks());

        // lastSeq still advanced
        $this->assertSame(20, $this->state->lastSeq);
    }

    /**
     * C2: RunHistoryPositionChanged followed by a normal event in the same poll batch must
     * include the post-position block in the returned result (not silently dropped).
     */
    public function testPollSyncsPostPositionEventsAfterRunHistoryPositionChanged(): void
    {
        // Thesis: after a RunHistoryPositionChanged triggers a wholesale transcript rebuild,
        // any normal event processed later in the same batch must be synchronised
        // into $state->transcript via drainChanges() → applyTranscriptChangeSet(), not lost.

        // Real projector that tracks accepted events and returns blocks
        $projector = new class implements TranscriptProjectorInterface {
            /** @var list<RuntimeEvent> */
            public array $accepted = [];
            public bool $wasReset = false;
            /** @var list<TranscriptBlock> */
            public array $hydrated = [];

            public function accept(RuntimeEvent $event): void
            {
                $this->accepted[] = $event;
            }

            public function blocks(): array
            {
                if ([] !== $this->hydrated) {
                    $blocks = $this->hydrated;
                    foreach ($this->accepted as $e) {
                        $blocks[] = new TranscriptBlock(
                            id: 'block-seq-'.$e->seq,
                            kind: TranscriptBlockKindEnum::AssistantMessage,
                            runId: 'test-run',
                            seq: $e->seq,
                            text: (string) ($e->payload['text'] ?? ''),
                        );
                    }

                    return $blocks;
                }

                $blocks = [];
                foreach ($this->accepted as $e) {
                    $blocks[] = new TranscriptBlock(
                        id: 'block-seq-'.$e->seq,
                        kind: TranscriptBlockKindEnum::AssistantMessage,
                        runId: 'test-run',
                        seq: $e->seq,
                        text: (string) ($e->payload['text'] ?? ''),
                    );
                }

                return $blocks;
            }

            public function drainChanges(): TranscriptChangeSet
            {
                $blocks = $this->blocks();
                if ([] !== $this->hydrated) {
                    // Only emit post-hydration accepted events as incremental dirty.
                    $blocks = [];
                    foreach ($this->accepted as $e) {
                        $blocks[] = new TranscriptBlock(
                            id: 'block-seq-'.$e->seq,
                            kind: TranscriptBlockKindEnum::AssistantMessage,
                            runId: 'test-run',
                            seq: $e->seq,
                            text: (string) ($e->payload['text'] ?? ''),
                        );
                    }
                }
                $this->accepted = [];

                return TranscriptChangeSet::incremental($blocks);
            }

            public function reset(): void
            {
                $this->accepted = [];
                $this->hydrated = [];
                $this->wasReset = true;
            }

            public function replaceProjectedBlocks(array $blocks): void
            {
                $this->hydrated = $blocks;
                $this->accepted = [];
            }
        };

        $sessionTranscriptProvider = $this->createMock(SessionTranscriptProviderInterface::class);
        $sessionTranscriptProvider->expects($this->once())
            ->method('transcriptAtPosition')
            ->with('test-run', 2)
            ->willReturn(new SessionTranscriptSnapshotDTO([
                new TranscriptBlock(
                    id: 'block-seq-30',
                    kind: TranscriptBlockKindEnum::AssistantMessage,
                    runId: 'test-run',
                    seq: 30,
                    text: 'Rebuilt retained history block',
                ),
            ], []));

        $eventApplier = new TuiRuntimeEventApplier($projector, SubagentProgressSerializerTestSupport::denormalizer());
        $poller = new RuntimeEventPoller(
            $eventApplier,
            $this->logger,
            new RuntimeExceptionBoundary(
                $this->createStub(EventDispatcherInterface::class),
            ),
            $sessionTranscriptProvider,
        );

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::RunHistoryPositionChanged->value,
                    runId: 'test-run',
                    seq: 20,
                    payload: ['position_turn_no' => 2],
                ),
                // Normal event arriving in the same batch after RunHistoryPositionChanged
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::AssistantMessageCompleted->value,
                    runId: 'test-run',
                    seq: 35,
                    payload: ['text' => 'Post-position event'],
                ),
            ]);

        $result = $poller->poll($this->state, $this->client);

        // Both the rebuilt retained-history block AND the post-position block must appear
        $this->assertInstanceOf(TranscriptChangeSet::class, $result, 'Result must not be null when events were processed');
        $this->assertTrue($result->isFull());
        $this->assertCount(2, $result->blocks(), 'Both rebuilt and post-position blocks must be present');
        $this->assertSame('block-seq-30', $result->blocks()[0]->id);
        $this->assertSame('Rebuilt retained history block', $result->blocks()[0]->text);
        $this->assertSame('block-seq-35', $result->blocks()[1]->id);
        $this->assertSame('Post-position event', $result->blocks()[1]->text);

        // lastSeq advanced to the highest seq in the batch (35, not 20)
        $this->assertSame(35, $this->state->lastSeq);
    }

    public function testHistoryPositionHydratesLiveProjectorForLaterCompactionRetention(): void
    {
        // Thesis: after RunHistoryPositionChanged, the live projector must hold the
        // isolated snapshot (not remain empty after reset). The next compaction.completed
        // can then find the previous completed marker and evict conversation #1.
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $projectionState = new \Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState();
        $dispatcher->addSubscriber(new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\CompactionProjectionSubscriber());
        $projector = new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector($dispatcher, $projectionState);

        $snapshotBlocks = [
            new TranscriptBlock(
                id: 'u1',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'test-run',
                seq: 1,
                text: 'conversation 1',
            ),
            new TranscriptBlock(
                id: 'compaction_completed_2',
                kind: TranscriptBlockKindEnum::System,
                runId: 'test-run',
                seq: 2,
                text: 'Conversation compacted.',
                meta: [
                    'category' => 'lifecycle',
                    'lifecycle' => 'compaction_completed',
                    'severity' => 'info',
                ],
            ),
            new TranscriptBlock(
                id: 'u2',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'test-run',
                seq: 3,
                text: 'conversation 2',
            ),
        ];

        $sessionTranscriptProvider = $this->createMock(SessionTranscriptProviderInterface::class);
        $sessionTranscriptProvider->expects($this->once())
            ->method('transcriptAtPosition')
            ->with('test-run', 2)
            ->willReturn(new SessionTranscriptSnapshotDTO($snapshotBlocks, []));

        $eventApplier = new TuiRuntimeEventApplier($projector, SubagentProgressSerializerTestSupport::denormalizer());
        $poller = new RuntimeEventPoller(
            $eventApplier,
            $this->logger,
            new RuntimeExceptionBoundary(
                $this->createStub(EventDispatcherInterface::class),
            ),
            $sessionTranscriptProvider,
        );

        $this->client->expects($this->exactly(2))
            ->method('events')
            ->willReturnOnConsecutiveCalls(
                [
                    new RuntimeEvent(
                        type: RuntimeEventTypeEnum::RunHistoryPositionChanged->value,
                        runId: 'test-run',
                        seq: 20,
                        payload: ['position_turn_no' => 2],
                    ),
                ],
                [
                    new RuntimeEvent(
                        type: RuntimeEventTypeEnum::CompactionCompleted->value,
                        runId: 'test-run',
                        seq: 30,
                        payload: [
                            'estimated_tokens_before' => 100,
                            'estimated_tokens_after' => 40,
                        ],
                    ),
                ],
            );

        $first = $poller->poll($this->state, $this->client);
        $this->assertInstanceOf(TranscriptChangeSet::class, $first);
        $this->assertTrue($first->isFull());
        $this->assertSame(['u1', 'compaction_completed_2', 'u2'], array_map(
            static fn (TranscriptBlock $block): string => $block->id,
            $this->state->transcript,
        ));
        $this->assertSame(
            ['u1', 'compaction_completed_2', 'u2'],
            array_map(static fn (TranscriptBlock $block): string => $block->id, $projector->blocks()),
            'Live projector must be hydrated from the history-position snapshot',
        );

        $this->state->lastPoll = 0.0;
        $second = $poller->poll($this->state, $this->client);
        $this->assertInstanceOf(TranscriptChangeSet::class, $second);
        $ids = array_map(static fn (TranscriptBlock $block): string => $block->id, $this->state->transcript);
        $this->assertNotContains('u1', $ids, 'Second compaction after history-position must evict conversation #1');
        $this->assertContains('u2', $ids);
        $this->assertContains('compaction_completed_2', $ids);
    }

    public function testCompactionRetentionDropsLocalUiBeforeFloorAndKeepsNewerLocals(): void
    {
        // Thesis: local UI blocks unknown to the runtime projector leave with their
        // segment on compaction retention, while newer locals after the floor remain.
        // Poller returns an authoritative full snapshot at the retention boundary.
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $projectionState = new \Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState();
        $dispatcher->addSubscriber(new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\CompactionProjectionSubscriber());
        $projector = new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector($dispatcher, $projectionState);
        $eventApplier = new TuiRuntimeEventApplier($projector, SubagentProgressSerializerTestSupport::denormalizer());
        $poller = new RuntimeEventPoller(
            $eventApplier,
            $this->logger,
            new RuntimeExceptionBoundary(
                $this->createStub(EventDispatcherInterface::class),
            ),
            $this->sessionTranscriptProvider,
        );

        $this->client->expects($this->exactly(2))
            ->method('events')
            ->willReturnOnConsecutiveCalls(
                [
                    new RuntimeEvent(
                        type: RuntimeEventTypeEnum::UserMessageSubmitted->value,
                        runId: 'test-run',
                        seq: 1,
                        payload: ['message_id' => 'u1', 'text' => 'conversation 1'],
                    ),
                    new RuntimeEvent(
                        type: RuntimeEventTypeEnum::CompactionCompleted->value,
                        runId: 'test-run',
                        seq: 10,
                        payload: [
                            'estimated_tokens_before' => 100,
                            'estimated_tokens_after' => 50,
                        ],
                    ),
                    new RuntimeEvent(
                        type: RuntimeEventTypeEnum::UserMessageSubmitted->value,
                        runId: 'test-run',
                        seq: 11,
                        payload: ['message_id' => 'u2', 'text' => 'conversation 2'],
                    ),
                ],
                [
                    new RuntimeEvent(
                        type: RuntimeEventTypeEnum::CompactionCompleted->value,
                        runId: 'test-run',
                        seq: 20,
                        payload: [
                            'estimated_tokens_before' => 90,
                            'estimated_tokens_after' => 40,
                        ],
                    ),
                ],
            );

        $first = $poller->poll($this->state, $this->client);
        $this->assertInstanceOf(TranscriptChangeSet::class, $first);

        $floorIdx = null;
        foreach ($this->state->transcript as $i => $block) {
            if ('compaction_completed' === ($block->meta['lifecycle'] ?? null)) {
                $floorIdx = $i;
                break;
            }
        }
        $this->assertNotNull($floorIdx);

        $localOld = new TranscriptBlock(
            id: 'local-old-error',
            kind: TranscriptBlockKindEnum::Error,
            runId: 'test-run',
            seq: 99,
            text: 'old local error in conversation 1 segment',
        );
        $rebuilt = $this->state->transcript;
        array_splice($rebuilt, (int) $floorIdx, 0, [$localOld]);
        $this->state->replaceTranscript($rebuilt);

        $this->state->appendTranscriptBlock(new TranscriptBlock(
            id: 'local-new-error',
            kind: TranscriptBlockKindEnum::Error,
            runId: 'test-run',
            seq: 100,
            text: 'newer local error after floor',
        ));

        $this->state->lastPoll = 0.0;
        $second = $poller->poll($this->state, $this->client);
        $this->assertInstanceOf(TranscriptChangeSet::class, $second);
        $this->assertTrue($second->isFull(), 'Retention boundary must return authoritative snapshot for mounted screen');
        $resultIds = array_map(static fn (TranscriptBlock $block): string => $block->id, $second->blocks());
        $this->assertNotContains('u1', $resultIds);
        $this->assertNotContains('local-old-error', $resultIds);
        $this->assertContains('u2', $resultIds);
        $this->assertContains('local-new-error', $resultIds);
        $this->assertSame($second->blocks(), $this->state->transcript);
    }

    public function testMultipleCompactionsInOnePollRetainOnlyLatestWindow(): void
    {
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $projectionState = new \Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState();
        $dispatcher->addSubscriber(new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\CompactionProjectionSubscriber());
        $projector = new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector($dispatcher, $projectionState);
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier($projector, SubagentProgressSerializerTestSupport::denormalizer()),
            $this->logger,
            new RuntimeExceptionBoundary($this->createStub(EventDispatcherInterface::class)),
            $this->sessionTranscriptProvider,
        );
        $this->state->appendTranscriptBlock(new TranscriptBlock(
            id: 'old-local', kind: TranscriptBlockKindEnum::Error,
            runId: 'test-run', seq: 999, text: 'old local notice',
        ));
        $events = [];
        for ($i = 1; $i <= 3; ++$i) {
            $events[] = new RuntimeEvent('user.message_submitted', 'test-run', $i * 2 - 1, [
                'message_id' => 'u'.$i, 'text' => 'conversation '.$i,
            ]);
            $events[] = new RuntimeEvent('compaction.completed', 'test-run', $i * 2, []);
        }
        $this->client->expects($this->once())->method('events')->willReturn($events);

        $result = $poller->poll($this->state, $this->client);

        $this->assertInstanceOf(TranscriptChangeSet::class, $result);
        $this->assertTrue($result->isFull());
        $this->assertSame($projector->blocks(), $result->blocks());
        $this->assertSame(['Conversation compacted.', 'conversation 3', 'Conversation compacted.'], array_map(
            static fn (TranscriptBlock $block): string => $block->text,
            $this->state->transcript,
        ));
    }

    public function testFullHistoryPositionReplaceClearsWithoutMergingLocalErrors(): void
    {
        // Thesis: full replacement must not preserve arbitrary Error blocks as "local UI".
        // Canonical/local errors present before history jump are cleared with the replaced transcript.
        $this->state->appendTranscriptBlock(new TranscriptBlock(
            id: 'old-error',
            kind: TranscriptBlockKindEnum::Error,
            runId: 'test-run',
            seq: 5,
            text: 'stale error',
        ));

        $sessionTranscriptProvider = $this->createMock(SessionTranscriptProviderInterface::class);
        $sessionTranscriptProvider->expects($this->once())
            ->method('transcriptAtPosition')
            ->with('test-run', 1)
            ->willReturn(new SessionTranscriptSnapshotDTO([
                new TranscriptBlock(
                    id: 'kept',
                    kind: TranscriptBlockKindEnum::UserMessage,
                    runId: 'test-run',
                    seq: 1,
                    text: 'retained',
                ),
            ], []));

        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier($this->projector, SubagentProgressSerializerTestSupport::denormalizer()),
            $this->logger,
            new RuntimeExceptionBoundary(
                $this->createStub(EventDispatcherInterface::class),
            ),
            $sessionTranscriptProvider,
        );

        $this->client->expects($this->once())
            ->method('events')
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::RunHistoryPositionChanged->value,
                    runId: 'test-run',
                    seq: 20,
                    payload: ['position_turn_no' => 1],
                ),
            ]);

        $result = $poller->poll($this->state, $this->client);
        $this->assertInstanceOf(TranscriptChangeSet::class, $result);
        $this->assertTrue($result->isFull());
        $this->assertSame(['kept'], array_map(static fn (TranscriptBlock $b): string => $b->id, $this->state->transcript));
        $this->assertNotContains('old-error', array_map(static fn (TranscriptBlock $b): string => $b->id, $this->state->transcript));
    }

    public function testAlwaysFailingRetainedEventReachesFatalBoundaryAndReleasesSuffix(): void
    {
        $event = new RuntimeEvent(type: RuntimeEventTypeEnum::TurnStarted->value, runId: 'test-run', seq: 1);
        $attempts = 0;
        $next = new RuntimeEvent(type: RuntimeEventTypeEnum::TurnStarted->value, runId: 'test-run', seq: 2);

        $this->client->expects($this->exactly(2))
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturnOnConsecutiveCalls([$event], [$next]);
        $this->projector->method('accept')->willReturnCallback(static function () use (&$attempts): void {
            ++$attempts;
            if ($attempts <= 3) {
                throw new \RuntimeException('projection failed');
            }
        });

        $this->assertNull($this->poller->poll($this->state, $this->client));
        $this->assertSame(0, $this->state->lastSeq);
        $this->state->lastPoll = 0.0;
        $this->assertNull($this->poller->poll($this->state, $this->client));
        $this->assertSame(0, $this->state->lastSeq);
        $this->state->lastPoll = 0.0;
        $this->assertInstanceOf(TranscriptChangeSet::class, $this->poller->poll($this->state, $this->client));
        $this->assertSame(0, $this->state->lastSeq, 'The cursor must not advance past the failed event.');
        $this->assertSame(3, $attempts, 'Retained failures must reach the three-strike boundary.');

        $this->state->lastPoll = 0.0;
        $this->poller->poll($this->state, $this->client);
        $this->assertSame(2, $this->state->lastSeq, 'Fatal handling must release the retained suffix for fresh pipe frames.');
    }

    public function testRunSwitchDropsRetainedEventsFromThePreviousRun(): void
    {
        $old = new RuntimeEvent(type: RuntimeEventTypeEnum::TurnStarted->value, runId: 'test-run', seq: 1);
        $new = new RuntimeEvent(type: RuntimeEventTypeEnum::TurnStarted->value, runId: 'new-run', seq: 2);

        $this->client->expects($this->exactly(2))
            ->method('events')
            ->willReturnCallback(static fn (string $runId): array => 'test-run' === $runId ? [$old] : [$new]);
        $this->projector->method('accept')->willReturnOnConsecutiveCalls(
            $this->throwException(new \RuntimeException('projection failed')),
            null,
        );

        $this->poller->poll($this->state, $this->client);
        $this->state->handle = new RunHandle(runId: 'new-run');
        $this->state->lastSeq = 0;
        $this->state->lastPoll = 0.0;

        $this->poller->poll($this->state, $this->client);

        $this->assertSame(2, $this->state->lastSeq);
    }

    public function testFailedEventKeepsCursorAndRetriesTheRemainingBatch(): void
    {
        $first = new RuntimeEvent(type: RuntimeEventTypeEnum::TurnStarted->value, runId: 'test-run', seq: 1);
        $second = new RuntimeEvent(type: RuntimeEventTypeEnum::TurnStarted->value, runId: 'test-run', seq: 2);
        $attempts = 0;

        $this->client->expects($this->once())
            ->method('events')
            ->with('test-run', $this->anything())
            ->willReturn([$first, $second]);
        $this->projector->method('accept')->willReturnCallback(static function () use (&$attempts): void {
            ++$attempts;
            if (1 === $attempts) {
                throw new \RuntimeException('projection failed');
            }
        });

        $this->assertNull($this->poller->poll($this->state, $this->client));
        $this->assertSame(0, $this->state->lastSeq, 'A failed event must not advance the canonical cursor.');

        $this->state->lastPoll = 0.0;
        $this->assertNull($this->poller->poll($this->state, $this->client));
        $this->assertSame(2, $this->state->lastSeq, 'The retained suffix must be applied after the failed event succeeds.');
        $this->assertSame(3, $attempts, 'The failed event and its following event must both be retried.');
    }
}
