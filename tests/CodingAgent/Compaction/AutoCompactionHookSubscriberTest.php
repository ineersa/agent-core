<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Compaction\ActiveModelResolverInterface;
use Ineersa\CodingAgent\Compaction\AutoCompactionHookSubscriber;
use Ineersa\CodingAgent\Compaction\CompactionTokenEstimator;
use Ineersa\CodingAgent\Config\CompactionConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @covers \Ineersa\CodingAgent\Compaction\AutoCompactionHookSubscriber
 */
final class AutoCompactionHookSubscriberTest extends TestCase
{
    private AutoCompactionHookSubscriber $subscriber;
    /** @var RunStoreInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $runStore;
    private CompactionTokenEstimator $tokenEstimator;
    private CompactionConfig $compactionConfig;
    /** @var ActiveModelResolverInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $modelResolver;
    /** @var MessageBusInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $commandBus;
    /** @var list<CompactRun> */
    private array $dispatchedMessages = [];

    protected function setUp(): void
    {
        $this->runStore = $this->createMock(RunStoreInterface::class);
        $this->tokenEstimator = new CompactionTokenEstimator();
        $this->compactionConfig = new CompactionConfig(
            autoEnabled: true,
            compactAfterTokens: 50,
            keepRecentTokens: 10,
        );
        $this->modelResolver = $this->createMock(ActiveModelResolverInterface::class);
        $this->commandBus = $this->createMock(MessageBusInterface::class);
        $this->dispatchedMessages = [];

        $this->commandBus->method('dispatch')
            ->willReturnCallback(function (object $message): Envelope {
                if ($message instanceof CompactRun) {
                    $this->dispatchedMessages[] = $message;
                }

                return new Envelope($message);
            });

        $this->subscriber = new AutoCompactionHookSubscriber(
            $this->runStore,
            $this->tokenEstimator,
            $this->compactionConfig,
            $this->modelResolver,
            $this->commandBus,
        );
    }

    private function createRunState(
        array $messages = [],
        ?string $activeStepId = null,
        RunStatus $status = RunStatus::Running,
    ): RunState {
        return new RunState(
            runId: 'run-1',
            status: $status,
            turnNo: 1,
            messages: $messages,
            activeStepId: $activeStepId,
        );
    }

    private function createHookContext(array $eventTypes = []): AfterTurnCommitHookContext
    {
        $events = array_map(
            static fn (string $type): AfterTurnCommitEventSummary => new AfterTurnCommitEventSummary(seq: 1, type: $type),
            $eventTypes,
        );

        return new AfterTurnCommitHookContext(
            runId: 'run-1',
            turnNo: 1,
            status: 'running',
            events: $events,
            effectsCount: 0,
        );
    }

    private function makeTextMessage(string $role, string $text): AgentMessage
    {
        return AgentMessage::fromPayload([
            'content' => [['text' => $text]],
            'role' => $role,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: auto compaction triggers when threshold exceeded
    // ─────────────────────────────────────────────────────────────────

    public function testDispatchesAutoCompactWhenTokenThresholdExceeded(): void
    {
        $this->modelResolver->expects(self::once())
            ->method('getActiveModel')
            ->with('run-1')
            ->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 200)), // ~62 tokens
        ];
        $runState = $this->createRunState($messages);

        $this->runStore->expects(self::once())
            ->method('get')
            ->with('run-1')
            ->willReturn($runState);

        $context = $this->createHookContext([]);
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(1, $this->dispatchedMessages);
        self::assertSame('auto', $this->dispatchedMessages[0]->trigger);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: does NOT trigger when below threshold
    // ─────────────────────────────────────────────────────────────────

    public function testDoesNotDispatchWhenBelowThreshold(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', 'Hello'), // ~8 tokens < 50
        ];
        $runState = $this->createRunState($messages);

        $this->runStore->method('get')->willReturn($runState);

        $this->subscriber->handleAfterTurnCommit($this->createHookContext([]));
        self::assertCount(0, $this->dispatchedMessages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: skips when auto disabled
    // ─────────────────────────────────────────────────────────────────

    public function testSkipsWhenAutoDisabled(): void
    {
        $this->compactionConfig = new CompactionConfig(
            autoEnabled: false,
            compactAfterTokens: 1,
        );
        $this->subscriber = new AutoCompactionHookSubscriber(
            $this->runStore,
            $this->tokenEstimator,
            $this->compactionConfig,
            $this->modelResolver,
            $this->commandBus,
        );

        $this->modelResolver->expects(self::once())->method('getActiveModel')->willReturn(null);
        $this->runStore->expects(self::never())->method('get');

        $this->subscriber->handleAfterTurnCommit($this->createHookContext([]));
        self::assertCount(0, $this->dispatchedMessages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: skip when compaction lifecycle events present
    // ─────────────────────────────────────────────────────────────────

    public function testSkipsWhenCompactionLifecycleEventsPresent(): void
    {
        $this->subscriber->handleAfterTurnCommit(
            $this->createHookContext([RunEventTypeEnum::ContextCompactionStarted->value]),
        );
        self::assertCount(0, $this->dispatchedMessages);
    }

    public function testSkipsWhenContextCompactedEventPresent(): void
    {
        $this->subscriber->handleAfterTurnCommit(
            $this->createHookContext([RunEventTypeEnum::ContextCompacted->value]),
        );
        self::assertCount(0, $this->dispatchedMessages);
    }

    public function testSkipsWhenContextCompactionFailedEventPresent(): void
    {
        $this->subscriber->handleAfterTurnCommit(
            $this->createHookContext([RunEventTypeEnum::ContextCompactionFailed->value]),
        );
        self::assertCount(0, $this->dispatchedMessages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: skip when compaction in flight (activeStepId)
    // ─────────────────────────────────────────────────────────────────

    public function testSkipsWhenCompactionAlreadyInFlight(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 200)),
        ];
        $runState = $this->createRunState($messages, activeStepId: 'compact-1234567890');
        $this->runStore->method('get')->willReturn($runState);

        $this->subscriber->handleAfterTurnCommit($this->createHookContext([]));
        self::assertCount(0, $this->dispatchedMessages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: in-process dedup
    // ─────────────────────────────────────────────────────────────────

    public function testDedupPreventsDoubleDispatchWithinSameProcess(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 200)),
        ];
        $this->runStore->method('get')->willReturn(
            $this->createRunState($messages),
        );

        // First call dispatches
        $this->subscriber->handleAfterTurnCommit($this->createHookContext([]));
        self::assertCount(1, $this->dispatchedMessages);

        // Second call (before lifecycle commit) skips
        $this->subscriber->handleAfterTurnCommit($this->createHookContext([]));
        self::assertCount(1, $this->dispatchedMessages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: dedup cleared after lifecycle commit
    // ─────────────────────────────────────────────────────────────────

    public function testDedupClearedAfterLifecycleCommit(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 200)),
        ];
        $this->runStore->method('get')->willReturn(
            $this->createRunState($messages),
        );

        // Dispatch
        $this->subscriber->handleAfterTurnCommit($this->createHookContext([]));
        self::assertCount(1, $this->dispatchedMessages);

        // Lifecycle commit clears dedup
        $this->subscriber->handleAfterTurnCommit(
            $this->createHookContext([RunEventTypeEnum::ContextCompactionStarted->value]),
        );

        // Next non-lifecycle commit dispatches again
        $this->subscriber->handleAfterTurnCommit($this->createHookContext([]));
        self::assertCount(2, $this->dispatchedMessages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: respects model overrides for threshold
    // ─────────────────────────────────────────────────────────────────

    public function testRespectsModelOverridesForThreshold(): void
    {
        $this->compactionConfig = new CompactionConfig(
            autoEnabled: true,
            compactAfterTokens: 50,
            modelOverrides: [
                'openai/gpt-4' => ['compact_after_tokens' => 10000],
            ],
        );
        $this->subscriber = new AutoCompactionHookSubscriber(
            $this->runStore,
            $this->tokenEstimator,
            $this->compactionConfig,
            $this->modelResolver,
            $this->commandBus,
        );

        $this->modelResolver->method('getActiveModel')
            ->with('run-1')
            ->willReturn('openai/gpt-4');

        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 200)), // ~62 tokens < 10000 override
        ];
        $this->runStore->method('get')->willReturn(
            $this->createRunState($messages),
        );

        $this->subscriber->handleAfterTurnCommit($this->createHookContext([]));
        self::assertCount(0, $this->dispatchedMessages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: no dispatch when run state missing
    // ─────────────────────────────────────────────────────────────────

    public function testDoesNotDispatchWhenRunStateMissing(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);
        $this->runStore->method('get')->willReturn(null);

        $this->subscriber->handleAfterTurnCommit($this->createHookContext([]));
        self::assertCount(0, $this->dispatchedMessages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: implements HookSubscriberInterface
    // ─────────────────────────────────────────────────────────────────

    public function testImplementsHookSubscriberInterface(): void
    {
        self::assertInstanceOf(HookSubscriberInterface::class, $this->subscriber);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: dispatched message has correct shape
    // ─────────────────────────────────────────────────────────────────

    public function testDispatchedCompactRunHasAutoTrigger(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 200)),
        ];
        $this->runStore->method('get')->willReturn(
            $this->createRunState($messages),
        );

        $this->subscriber->handleAfterTurnCommit($this->createHookContext([]));

        self::assertCount(1, $this->dispatchedMessages);
        $msg = $this->dispatchedMessages[0];
        self::assertSame('auto', $msg->trigger);
        self::assertNull($msg->customInstructions);
        self::assertSame('run-1', $msg->runId());
        self::assertStringStartsWith('compact-', $msg->stepId());
    }
}
