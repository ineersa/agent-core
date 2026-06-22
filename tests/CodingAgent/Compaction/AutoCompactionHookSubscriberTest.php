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
    //  Test: dedup cleared BUT compactionResolved prevents re-dispatch
    //        until next user turn (run_started) resets the flag
    // ─────────────────────────────────────────────────────────────────

    public function testDedupClearedButCompactionResolvedPreventsRedispatch(): void
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

        // Lifecycle commit clears inFlight AND sets compactionResolved
        $this->subscriber->handleAfterTurnCommit(
            $this->createHookContext([RunEventTypeEnum::ContextCompactionStarted->value]),
        );

        // Next non-lifecycle commit must NOT dispatch again —
        // compaction was already resolved for this logical turn.
        $this->subscriber->handleAfterTurnCommit($this->createHookContext([]));
        self::assertCount(1, $this->dispatchedMessages);
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
    //  Test: skips when effectsCount > 0 (intermediate orchestration)
    // ─────────────────────────────────────────────────────────────────

    public function testSkipsWhenRunStartedEventPresent(): void
    {
        // Skip the model resolver call since run_started returns early
        $this->modelResolver->expects(self::never())->method('getActiveModel');

        $ctx = $this->createHookContext([
            RunEventTypeEnum::RunStarted->value,
        ]);

        $this->subscriber->handleAfterTurnCommit($ctx);
        self::assertCount(0, $this->dispatchedMessages);
    }

    /**
     * A commit with outbound effects (e.g. StartRun producing AdvanceRun,
     * or AdvanceRun producing CompactRun/ExecuteLlmStep) is intermediate —
     * effects will produce follow-up commits.  The hook must not evaluate
     * auto-compaction on these intermediate commits because the follow-up
     * may also satisfy the threshold, causing duplicate dispatches.
     *
     * Without this guard, three compact dispatch paths can stack:
     *  hook after effect-producing commit → CompactRun #1
     *  pre-LLM guard → CompactRun #2
     *  hook after pre-LLM-guard commit → CompactRun #3
     *
     * With effectsCount > 0, only the pre-LLM guard path dispatches once.
     */
    public function testSkipsWhenEffectsCountGreaterThanZero(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 200)), // ~62 tokens > 50
        ];
        $this->runStore->method('get')->willReturn(
            $this->createRunState($messages),
        );

        $context = new AfterTurnCommitHookContext(
            runId: 'run-1',
            turnNo: 1,
            status: 'running',
            events: [],
            effectsCount: 1, // intermediate commit with outbound effects
        );

        $this->subscriber->handleAfterTurnCommit($context);
        self::assertCount(0, $this->dispatchedMessages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: skips after compaction lifecycle is resolved (no duplicate
    //        dispatch on stable follow-up commit)
    // ─────────────────────────────────────────────────────────────────

    /**
     * After the pre-LLM guard dispatches a CompactRun and its lifecycle
     * commit is observed (context_compaction_failed/started/compacted),
     * the CompactionStepResultHandler dispatches a continuation AdvanceRun.
     * That AdvanceRun advances the turn and the LLM step runs.  The
     * subsequent stable commit (effectsCount=0) must NOT re-trigger
     * auto-compaction because the compaction lifecycle was already
     * resolved for this logical user turn.
     */
    public function testSkipsAfterCompactionLifecycleResolved(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 200)),
        ];
        // Simulate a later turn after compaction resolved
        $runState = $this->createRunState($messages);
        $this->runStore->method('get')->willReturn($runState);

        // Step 1: lifecycle commit marks compaction as resolved
        $lifecycleCtx = $this->createHookContext([
            RunEventTypeEnum::ContextCompactionFailed->value,
        ]);
        $this->subscriber->handleAfterTurnCommit($lifecycleCtx);
        self::assertCount(0, $this->dispatchedMessages, 'Lifecycle commit must not dispatch');

        // Step 2: stable follow-up commit (e.g. LLM step result) with
        // effectsCount=0 and no lifecycle events.  Without the
        // compactionResolved guard, the hook would re-dispatch here.
        $stableCtx = $this->createHookContext([]);
        $this->subscriber->handleAfterTurnCommit($stableCtx);
        self::assertCount(
            0,
            $this->dispatchedMessages,
            'Stable commit after compaction lifecycle must not re-dispatch',
        );
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: compactionResolved cleared on new user turn (run_started)
    // ─────────────────────────────────────────────────────────────────

    /**
     * After a compaction lifecycle was resolved, the next user prompt
     * starts with a run_started event.  The hook must clear the
     * compactionResolved flag AND return early (no dispatch on StartRun),
     * so auto-compaction can fire again on the next stable commit.
     */
    public function testCompactionResolvedClearedOnNewUserTurn(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 200)),
        ];
        $this->runStore->method('get')->willReturn(
            $this->createRunState($messages),
        );

        // Step 1: lifecycle commit marks compaction as resolved
        $lifecycleCtx = $this->createHookContext([
            RunEventTypeEnum::ContextCompactionFailed->value,
        ]);
        $this->subscriber->handleAfterTurnCommit($lifecycleCtx);

        // Step 2: new user turn — run_started clears the flag and
        // returns early (StartRun commits never trigger auto-compaction;
        // that's handled by the pre-LLM guard or the next effectsCount=0
        // commit).
        $startCtx = new AfterTurnCommitHookContext(
            runId: 'run-1',
            turnNo: 2,
            status: 'running',
            events: [
                new AfterTurnCommitEventSummary(seq: 5, type: RunEventTypeEnum::RunStarted->value),
            ],
            effectsCount: 0, // StartRun dispatches AdvanceRun via bus, not effects
        );

        $result = $this->subscriber->handleAfterTurnCommit($startCtx);
        self::assertSame($startCtx, $result);
        self::assertCount(
            0,
            $this->dispatchedMessages,
            'StartRun commit must not dispatch auto-compaction (handled by pre-LLM guard)',
        );

        // Step 3: stable commit after new turn → should dispatch
        // because compactionResolved was cleared by run_started
        $stableCtx = $this->createHookContext([]);
        $this->subscriber->handleAfterTurnCommit($stableCtx);
        self::assertCount(
            1,
            $this->dispatchedMessages,
            'Stable commit after new user turn must dispatch auto-compaction',
        );
        self::assertSame('auto', $this->dispatchedMessages[0]->trigger);
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
