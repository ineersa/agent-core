<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Compaction\ActiveModelResolverInterface;
use Ineersa\CodingAgent\Compaction\AutoCompactionHookSubscriber;
use Ineersa\CodingAgent\Compaction\ProviderContextUsageResolver;
use Ineersa\CodingAgent\Config\CompactionConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Compaction\AutoCompactionHookSubscriber
 *
 * Auto-compaction trigger now uses provider-reported usage (from
 * llm_step_completed events) as the authoritative context size.
 * The text-only CompactionTokenEstimator is no longer the trigger
 * baseline — it undercounts real provider context.
 */
#[AllowMockObjectsWithoutExpectations]
final class AutoCompactionHookSubscriberTest extends TestCase
{
    private AutoCompactionHookSubscriber $subscriber;
    /** @var RunStoreInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $runStore;
    /** @var EventStoreInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventStore;
    private ProviderContextUsageResolver $providerUsageResolver;
    private CompactionConfig $compactionConfig;
    /** @var ActiveModelResolverInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $modelResolver;
    private TestMessageBus $commandBus;

    protected function setUp(): void
    {
        $this->runStore = $this->createMock(RunStoreInterface::class);
        $this->eventStore = $this->createMock(EventStoreInterface::class);
        $this->providerUsageResolver = new ProviderContextUsageResolver($this->eventStore);
        $this->compactionConfig = new CompactionConfig(
            autoEnabled: true,
            compactAfterTokens: 11000,
            keepRecentTokens: 10,
        );
        $this->modelResolver = $this->createMock(ActiveModelResolverInterface::class);
        $this->commandBus = new TestMessageBus();

        $this->subscriber = new AutoCompactionHookSubscriber(
            $this->runStore,
            $this->providerUsageResolver,
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

    private function createHookContext(array $eventTypes = [], int $effectsCount = 0): AfterTurnCommitHookContext
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
            effectsCount: $effectsCount,
        );
    }

    private function makeTextMessage(string $role, string $text): AgentMessage
    {
        return AgentMessage::fromPayload([
            'content' => [['text' => $text]],
            'role' => $role,
        ]);
    }

    /**
     * Create a RunEvent stub for llm_step_completed with given input_tokens.
     */
    private function makeLlmStepCompletedEvent(int $inputTokens): RunEvent
    {
        return new RunEvent(
            runId: 'run-1',
            seq: 1,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 'step-1',
                'stop_reason' => 'stop',
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => 100,
                    'total_tokens' => $inputTokens + 100,
                ],
            ],
        );
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: auto compaction triggers when provider usage exceeds threshold
    // ─────────────────────────────────────────────────────────────────

    public function testDispatchesAutoCompactWhenProviderUsageExceedsThreshold(): void
    {
        $this->modelResolver->expects(self::once())
            ->method('getActiveModel')
            ->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', 'Hello'), // text estimator would give ~2 tokens, well below 11000
        ];
        $runState = $this->createRunState($messages);

        $this->runStore->expects(self::once())
            ->method('get')
            ->willReturn($runState);

        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]);  // 12000 > 11000, no auto started event

        $context = $this->createHookContext();
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(1, $this->commandBus->messages);
        self::assertSame('auto', $this->commandBus->messages[0]->trigger);
    }

    /**
     * Thesis: when provider usage exists but is below threshold, no auto-compaction
     * even though the text-only estimator might say otherwise.
     */
    public function testDoesNotDispatchWhenProviderUsageBelowThreshold(): void
    {
        $this->modelResolver->expects(self::once())
            ->method('getActiveModel')
            ->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 50000)), // estimator would say 15384 > 11000
        ];
        $runState = $this->createRunState($messages);

        $this->runStore->expects(self::once())
            ->method('get')
            ->willReturn($runState);

        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(5000)]);  // 5000 < 11000

        $context = $this->createHookContext();
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages);
    }

    /**
     * Thesis: no provider measurement → no auto-compaction.
     * The text-only estimator is NOT a fallback trigger baseline.
     */
    public function testDoesNotDispatchWhenNoProviderUsageExists(): void
    {
        $this->modelResolver->expects(self::once())
            ->method('getActiveModel')
            ->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 50000)), // would exceed if we used estimator
        ];
        $runState = $this->createRunState($messages);

        $this->runStore->expects(self::once())
            ->method('get')
            ->willReturn($runState);

        // No llm_step_completed events at all.
        $this->eventStore->method('allFor')
            ->willReturn([]);

        $context = $this->createHookContext();
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: auto disabled
    // ─────────────────────────────────────────────────────────────────

    public function testSkipsWhenAutoDisabled(): void
    {
        $disabledConfig = new CompactionConfig(
            autoEnabled: false,
            compactAfterTokens: 1,
        );
        $subscriber = new AutoCompactionHookSubscriber(
            $this->runStore,
            $this->providerUsageResolver,
            $disabledConfig,
            $this->modelResolver,
            $this->commandBus,
        );

        $context = $this->createHookContext();
        $subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: lifecycle guards (unchanged — independent of token source)
    // ─────────────────────────────────────────────────────────────────

    public function testSkipsWhenCompactionLifecycleEventsPresent(): void
    {
        $context = $this->createHookContext([RunEventTypeEnum::ContextCompactionStarted->value]);
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages);
    }

    public function testSkipsWhenContextCompactedEventPresent(): void
    {
        $context = $this->createHookContext([RunEventTypeEnum::ContextCompacted->value]);
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages);
    }

    public function testSkipsWhenContextCompactionFailedEventPresent(): void
    {
        $context = $this->createHookContext([RunEventTypeEnum::ContextCompactionFailed->value]);
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: in-flight compaction guard
    // ─────────────────────────────────────────────────────────────────

    public function testSkipsWhenCompactionAlreadyInFlight(): void
    {
        $this->modelResolver->expects(self::once())
            ->method('getActiveModel')
            ->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', 'Hello'),
        ];
        $runState = $this->createRunState($messages, activeStepId: 'compact-1234567890');

        $this->runStore->expects(self::once())
            ->method('get')
            ->willReturn($runState);

        $context = $this->createHookContext();
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: in-process dedup
    // ─────────────────────────────────────────────────────────────────

    public function testDedupPreventsDoubleDispatchWithinSameProcess(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $runState = $this->createRunState([
            $this->makeTextMessage('user', 'Hello'),
        ]);

        $this->runStore->method('get')->willReturn($runState);

        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]); // exceeds 11000

        // First call → dispatches.
        $this->subscriber->handleAfterTurnCommit($this->createHookContext());
        self::assertCount(1, $this->commandBus->messages);

        // Second call → dedup prevents dispatch (inFlight guard).
        $this->subscriber->handleAfterTurnCommit($this->createHookContext());
        self::assertCount(1, $this->commandBus->messages);
    }

    public function testDedupClearedButCompactionResolvedPreventsRedispatch(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', 'Hello'),
        ];
        $runState = $this->createRunState($messages);

        $this->runStore->method('get')->willReturn($runState);

        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]);

        // First call — dispatches.
        $this->subscriber->handleAfterTurnCommit($this->createHookContext());
        self::assertCount(1, $this->commandBus->messages);

        // Simulate lifecycle commit (clears inFlight, sets compactionResolved).
        $lifecycleContext = $this->createHookContext([RunEventTypeEnum::ContextCompactionFailed->value]);
        $this->subscriber->handleAfterTurnCommit($lifecycleContext);

        // Post-lifecycle stable commit — compactionResolved set → no dispatch,
        // even though inFlight was cleared above.
        $stableContext = $this->createHookContext();
        $this->subscriber->handleAfterTurnCommit($stableContext);
        self::assertCount(1, $this->commandBus->messages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: per-model override
    // ─────────────────────────────────────────────────────────────────

    public function testRespectsModelOverridesForThreshold(): void
    {
        $configWithOverride = new CompactionConfig(
            autoEnabled: true,
            compactAfterTokens: 11000,
            modelOverrides: [
                'openai/gpt-4' => ['compact_after_tokens' => 50000],
            ],
        );
        $modelResolver = $this->createMock(ActiveModelResolverInterface::class);
        $modelResolver->expects(self::once())
            ->method('getActiveModel')
            ->willReturn('openai/gpt-4');

        $subscriber = new AutoCompactionHookSubscriber(
            $this->runStore,
            $this->providerUsageResolver,
            $configWithOverride,
            $modelResolver,
            $this->commandBus,
        );

        $messages = [
            $this->makeTextMessage('user', 'Hello'),
        ];
        $runState = $this->createRunState($messages);

        $this->runStore->expects(self::once())
            ->method('get')
            ->willReturn($runState);

        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]); // 12000 < 50000 override

        $context = $this->createHookContext();
        $subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: null run state
    // ─────────────────────────────────────────────────────────────────

    public function testDoesNotDispatchWhenRunStateMissing(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);
        $this->runStore->method('get')->willReturn(null);

        $context = $this->createHookContext();
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: interface contract
    // ─────────────────────────────────────────────────────────────────

    public function testImplementsHookSubscriberInterface(): void
    {
        self::assertInstanceOf(HookSubscriberInterface::class, $this->subscriber);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: run_started clears compactionResolved
    // ─────────────────────────────────────────────────────────────────

    public function testSkipsWhenRunStartedEventPresent(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $runState = $this->createRunState([
            $this->makeTextMessage('user', 'Hello'),
        ]);
        $this->runStore->method('get')->willReturn($runState);
        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]);

        // RunStarted clears the resolved flag and returns early (no dispatch).
        $context = $this->createHookContext([RunEventTypeEnum::RunStarted->value]);
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: effectsCount > 0 → skip
    // ─────────────────────────────────────────────────────────────────

    public function testSkipsWhenEffectsCountGreaterThanZero(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $runState = $this->createRunState([
            $this->makeTextMessage('user', 'Hello'),
        ]);
        $this->runStore->method('get')->willReturn($runState);
        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]);

        // effectsCount > 0 means intermediate orchestration commit — skip.
        $context = $this->createHookContext(effectsCount: 2);
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: compactionResolved prevents re-dispatch after lifecycle
    // ─────────────────────────────────────────────────────────────────

    public function testSkipsAfterCompactionLifecycleResolved(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $runState = $this->createRunState([
            $this->makeTextMessage('user', 'Hello'),
        ]);
        $this->runStore->method('get')->willReturn($runState);
        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]);

        // Pre-condition: dispatch auto-compaction.
        $this->subscriber->handleAfterTurnCommit($this->createHookContext());
        self::assertCount(1, $this->commandBus->messages, 'Should have dispatched before lifecycle');

        // Simulate lifecycle commit (sets compactionResolved).
        $lifecycleContext = $this->createHookContext([RunEventTypeEnum::ContextCompactionFailed->value]);
        $this->subscriber->handleAfterTurnCommit($lifecycleContext);

        // Post-lifecycle stable commit — must NOT dispatch.
        $this->subscriber->handleAfterTurnCommit($this->createHookContext());
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: compactionResolved cleared on new user turn
    // ─────────────────────────────────────────────────────────────────

    public function testCompactionResolvedClearedOnNewUserTurn(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $runState = $this->createRunState([
            $this->makeTextMessage('user', 'Hello'),
        ]);
        $this->runStore->method('get')->willReturn($runState);
        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]);

        // Pre-condition: dispatch auto-compaction, then lifecycle sets resolved.
        $this->subscriber->handleAfterTurnCommit($this->createHookContext());
        self::assertCount(1, $this->commandBus->messages);

        $lifecycleContext = $this->createHookContext([RunEventTypeEnum::ContextCompactionFailed->value]);
        $this->subscriber->handleAfterTurnCommit($lifecycleContext);

        // New user turn (run_started) — clears compactionResolved.
        $this->subscriber->handleAfterTurnCommit(
            $this->createHookContext([RunEventTypeEnum::RunStarted->value]),
        );

        // After new turn, provider usage still > threshold → should dispatch again.
        $this->subscriber->handleAfterTurnCommit($this->createHookContext());
        self::assertCount(2, $this->commandBus->messages);
        self::assertSame('auto', $this->commandBus->messages[1]->trigger);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: dispatched CompactRun has auto trigger
    // ─────────────────────────────────────────────────────────────────

    public function testDispatchedCompactRunHasAutoTrigger(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $runState = $this->createRunState([
            $this->makeTextMessage('user', 'Hello'),
        ]);
        $this->runStore->method('get')->willReturn($runState);
        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]);

        $this->subscriber->handleAfterTurnCommit($this->createHookContext());

        self::assertCount(1, $this->commandBus->messages);
        $msg = $this->commandBus->messages[0];
        self::assertInstanceOf(CompactRun::class, $msg);
        self::assertSame('auto', $msg->trigger);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: stale provider measurement blocked by event-log eligibility
    // ─────────────────────────────────────────────────────────────────

    /**
     * Thesis: after auto compaction starts on a provider measurement,
     * the same measurement must NOT trigger another CompactRun dispatch
     * — even when the in-memory dedup maps are cleared (e.g. process
     * restart).  The event-log-eligibility check via
     * ProviderContextUsageResolver::getLatestEligibleInputTokens is the
     * authoritative guard.
     */
    public function testStaleProviderMeasurementDoesNotRetriggerAfterAutoCompactionStart(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $runState = $this->createRunState([
            $this->makeTextMessage('user', 'Hello'),
        ]);
        $this->runStore->method('get')->willReturn($runState);

        // Event log: provider measurement at seq 10, auto started at seq 11.
        // The resolver sees that the auto start (seq 11) > provider (seq 10),
        // so the measurement is INELIGIBLE.
        $this->eventStore->method('allFor')
            ->willReturn([
                $this->makeLlmStepCompletedEvent(12000),
                new RunEvent(
                    runId: 'run-1',
                    seq: 2,
                    turnNo: 1,
                    type: RunEventTypeEnum::ContextCompactionStarted->value,
                    payload: [
                        'step_id' => 'compact-99',
                        'trigger' => 'auto',
                        'estimated_tokens' => 12000,
                        'keep_recent_tokens' => 10,
                        'messages_before' => 10,
                        'messages_to_summarize' => 5,
                        'messages_retained' => 5,
                        'first_retained_index' => 5,
                        'prior_summary_present' => false,
                    ],
                ),
            ]);

        // Stable commit after process restart — in-memory maps are empty,
        // but event log says auto start already covered this measurement.
        $context = $this->createHookContext();
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages);
    }
}
