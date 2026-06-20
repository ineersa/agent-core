<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
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
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->poller = new RuntimeEventPoller(
            $this->projector,
            $this->logger,
            new RuntimeExceptionBoundary(
                self::createStub(EventDispatcherInterface::class),
            ),
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
                self::callback(static fn ($cmd): bool => $cmd instanceof UserCommand
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
}
