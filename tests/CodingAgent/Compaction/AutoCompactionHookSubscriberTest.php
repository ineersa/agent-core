<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\AgentCore\Contract\Compaction\CompactResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
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
    /** @var CompactionServiceInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $compactionService;
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
        $this->compactionService = $this->createMock(CompactionServiceInterface::class);
        // Default: preparation is ready and contains fresh non-summary messages.
        // Individual tests that test the summary-only or preparation-failure
        // guards override this via expects()+willReturn().
        $this->compactionService->method('prepare')
            ->willReturn(CompactionPrepareResult::ready(
                messagesToSummarize: [
                    new AgentMessage(
                        role: 'user',
                        content: [['type' => 'text', 'text' => 'Some message']],
                    ),
                ],
                retainedTailMessages: [],
                tokenEstimateBefore: 100,
                messagesCompacted: 1,
                messagesRetained: 0,
                firstRetainedIndex: 1,
                priorSummaryPresent: false,
            ));
        $this->commandBus = new TestMessageBus();

        $this->subscriber = new AutoCompactionHookSubscriber(
            $this->runStore,
            $this->providerUsageResolver,
            $this->compactionConfig,
            $this->modelResolver,
            $this->commandBus,
            $this->compactionService,
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

    private function makeCompactSummaryMessage(string $text = 'Prior conversation summary.'): AgentMessage
    {
        return new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $text]],
            metadata: ['compact_summary' => true],
        );
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
            $this->compactionService,
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

    public function testDedupClearedAndEligibilityPreventsRedispatchOnStaleMeasurement(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', 'Hello'),
        ];
        $runState = $this->createRunState($messages);

        $this->runStore->method('get')->willReturn($runState);

        // Event store: measurement at seq 1; auto attempt at seq 5.
        // The measurement is ineligible (1 <= 5) after the attempt.
        $this->eventStore->method('allFor')
            ->willReturn([
                $this->makeLlmStepCompletedEvent(12000),
                new RunEvent(
                    runId: 'run-1',
                    seq: 5,
                    turnNo: 1,
                    type: RunEventTypeEnum::ContextCompactionFailed->value,
                    payload: ['trigger' => 'auto', 'reason' => 'no_safe_boundary'],
                ),
            ]);

        // First call — dispatches (no attempt markers yet at this point...
        // Wait, the mock always returns both events, so the auto attempt
        // already exists.  The measurement is ineligible → no dispatch.
        //
        // To test the thesis, we need to verify that:
        //  1. The lifecycle commit clears inFlight.
        //  2. A subsequent stable commit does NOT dispatch because the
        //     measurement is ineligible via event-log lookup.
        //
        // Simulate lifecycle commit (clears inFlight).
        $lifecycleContext = $this->createHookContext([RunEventTypeEnum::ContextCompactionFailed->value]);
        $this->subscriber->handleAfterTurnCommit($lifecycleContext);

        // Stable commit: measurement is ineligible via event-log.
        $this->subscriber->handleAfterTurnCommit($this->createHookContext());
        self::assertCount(0, $this->commandBus->messages,
            'Stale measurement (seq=1, attempt seq=5) must NOT trigger dispatch');
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
            $this->compactionService,
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

    /**
     * Thesis: after a compaction lifecycle commit, a subsequent stable
     * commit with a STALE provider measurement (seq <= latest auto
     * attempt marker) must NOT dispatch.  Event-log eligibility replaces
     * the removed in-memory compactionResolved dedup.
     */
    public function testSkipsAfterCompactionLifecycleWhenMeasurementIsStale(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $runState = $this->createRunState([
            $this->makeTextMessage('user', 'Hello'),
        ]);
        $this->runStore->method('get')->willReturn($runState);

        // Event store: measurement at seq 1; auto attempt at seq 5.
        // Measurement is ineligible (seq 1 <= seq 5).
        $this->eventStore->method('allFor')
            ->willReturn([
                $this->makeLlmStepCompletedEvent(12000),
                new RunEvent(
                    runId: 'run-1',
                    seq: 5,
                    turnNo: 1,
                    type: RunEventTypeEnum::ContextCompactionStarted->value,
                    payload: ['trigger' => 'auto', 'step_id' => 'compact-1'],
                ),
            ]);

        // Pre-condition: simulate lifecycle commit (clears inFlight).
        $lifecycleContext = $this->createHookContext([RunEventTypeEnum::ContextCompactionStarted->value]);
        $this->subscriber->handleAfterTurnCommit($lifecycleContext);

        // Post-lifecycle stable commit — measurement is ineligible → no dispatch.
        $this->subscriber->handleAfterTurnCommit($this->createHookContext());
        self::assertCount(0, $this->commandBus->messages,
            'Stale provider measurement must NOT trigger dispatch after lifecycle. '
            .'Event-log eligibility (seq 1 <= attempt seq 5) replaces compactionResolved.');
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
     * Thesis: after auto compaction fails via the prepare-failure path
     * (context_compaction_failed with no preceding started event —
     * e.g. no_safe_boundary), the same provider measurement must NOT
     * trigger another CompactRun dispatch — even from a fresh service
     * instance (event-log persistence, not in-memory dedup only).
     *
     * This catches the session 3 seq74→seq79→seq87 class where
     * d11039e0f would allow retry because it only checked for
     * context_compaction_started as the attempt marker.
     */
    public function testFailureOnlyAutoMarkerBlocksDispatchFromFreshInstance(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $runState = $this->createRunState([
            $this->makeTextMessage('user', 'Hello'),
        ]);
        $this->runStore->method('get')->willReturn($runState);

        // Event log: provider measurement at seq 74, auto failed-only at
        // seq 79 (no started event — the prepare-failure path).
        $this->eventStore->method('allFor')
            ->willReturn([
                new RunEvent(
                    runId: 'run-1',
                    seq: 74,
                    turnNo: 1,
                    type: RunEventTypeEnum::LlmStepCompleted->value,
                    payload: [
                        'step_id' => 'step-74',
                        'stop_reason' => 'stop',
                        'usage' => [
                            'input_tokens' => 32660,
                            'output_tokens' => 100,
                            'total_tokens' => 32760,
                        ],
                    ],
                ),
                new RunEvent(
                    runId: 'run-1',
                    seq: 79,
                    turnNo: 1,
                    type: RunEventTypeEnum::ContextCompactionFailed->value,
                    payload: [
                        'reason' => 'no_safe_boundary',
                        'trigger' => 'auto',
                        'step_id' => null,
                        'messages_replaced' => false,
                    ],
                ),
            ]);

        // Fresh service instance — no in-memory state.
        $freshSubscriber = new AutoCompactionHookSubscriber(
            $this->runStore,
            $this->providerUsageResolver,
            $this->compactionConfig,
            $this->modelResolver,
            $this->commandBus,
            $this->compactionService,
        );

        $freshSubscriber->handleAfterTurnCommit($this->createHookContext());

        self::assertCount(0, $this->commandBus->messages,
            'Failure-only auto marker at seq 79 must block dispatch from stale provider measurement at seq 74');
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: tool_execution_start prevents auto-compaction
    // ─────────────────────────────────────────────────────────────────

    /**
     * Thesis: when the LLM step returns tool_calls, LlmStepResultHandler
     * emits tool_execution_start events and dispatches ExecuteToolCall
     * effects via a postCommit callback (not HandlerResult effects).
     * This produces effectsCount=0, which would otherwise make the
     * hook treat this as a stable turn-level commit.
     *
     * The ToolExecutionStart guard prevents mid-cycle auto-compaction
     * before tools have started executing.
     */
    public function testSkipsWhenToolExecutionStartEventPresent(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $runState = $this->createRunState([
            $this->makeTextMessage('user', 'Hello'),
        ]);
        $this->runStore->method('get')->willReturn($runState);

        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]);

        $context = $this->createHookContext(
            eventTypes: [RunEventTypeEnum::ToolExecutionStart->value],
            effectsCount: 0,
        );
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages,
            'ToolExecutionStart commit must NOT dispatch CompactRun '
            .'even when provider usage exceeds threshold (effectsCount=0). '
            .'Tool dispatch via postCommit must proceed without interruption.');
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: tool_batch_committed prevents auto-compaction (session 5)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Thesis: when a tool batch completes (ToolBatchCommitted event),
     * ToolCallResultHandler schedules the post-tool AdvanceRun as a
     * postCommit callback, NOT via HandlerResult effects.  This means
     * effectsCount=0, which would otherwise make the hook treat this
     * as a stable turn-level commit eligible for auto-compaction.
     *
     * If auto-compaction fires here, it sets status=Compacting before
     * the postCommit AdvanceRun executes.  AdvanceRunHandler's
     * Compacting guard then swallows the continuation, the final
     * assistant answer is lost, and the run dead-ends.
     *
     * The ToolBatchCommitted guard prevents this by returning early
     * without dispatching CompactRun, even when provider usage exceeds
     * the threshold and effectsCount is zero.
     */
    public function testSkipsWhenToolBatchCommittedEventPresent(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $runState = $this->createRunState([
            $this->makeTextMessage('user', 'Hello'),
        ]);
        $this->runStore->method('get')->willReturn($runState);

        // Provider usage exceeds threshold — the hook WOULD dispatch
        // auto-compaction if not for the ToolBatchCommitted guard.
        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]); // 12000 > 11000

        $context = $this->createHookContext(
            eventTypes: [RunEventTypeEnum::ToolBatchCommitted->value],
            effectsCount: 0,
        );
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages,
            'ToolBatchCommitted commit must NOT dispatch CompactRun '
            .'even when provider usage exceeds threshold (effectsCount=0). '
            .'The postCommit AdvanceRun must proceed without interruption.');
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: compactionResolved removed — event-log eligibility replaces it
    // ─────────────────────────────────────────────────────────────────

    /**
     * Thesis: after a compaction lifecycle commit, a new stable
     * commit with a FRESH eligible provider measurement (higher seq
     * than the latest auto attempt marker) MUST trigger another
     * auto-compaction dispatch — even within the same run.
     *
     * The compactionResolved flag permanently blocks this on HEAD
     * (RED), because run_started never fires after the first turn.
     *
     * Event-log eligibility in ProviderContextUsageResolver already
     * prevents reusing the same provider measurement — in-memory
     * compactionResolved dedup across turns is redundant and harmful.
     */
    public function testAllowsAutoCompactionOnLaterTurnWithFreshProviderUsage(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $runState = $this->createRunState([
            $this->makeTextMessage('user', 'Hello'),
        ]);
        $this->runStore->method('get')->willReturn($runState);

        // Event log:
        //  - OLD provider measurement at seq 1 (12000 > 11000)
        //  - First auto attempt marker at seq 5 (context_compaction_started)
        //  - NEW provider measurement at seq 10 (15000 > 11000, seq=10 > 5)
        $this->eventStore->method('allFor')
            ->willReturn([
                $this->makeLlmStepCompletedEvent(12000),
                new RunEvent(
                    runId: 'run-1',
                    seq: 5,
                    turnNo: 1,
                    type: RunEventTypeEnum::ContextCompactionStarted->value,
                    payload: [
                        'trigger' => 'auto',
                        'step_id' => 'compact-123',
                        'reason' => 'threshold_exceeded',
                    ],
                ),
                new RunEvent(
                    runId: 'run-1',
                    seq: 10,
                    turnNo: 2,
                    type: RunEventTypeEnum::LlmStepCompleted->value,
                    payload: [
                        'step_id' => 'step-10',
                        'stop_reason' => 'stop',
                        'usage' => [
                            'input_tokens' => 15000,
                            'output_tokens' => 100,
                            'total_tokens' => 15100,
                        ],
                    ],
                ),
            ]);

        // Step 1: simulate compaction lifecycle commit (sets compactionResolved on HEAD).
        $lifecycleContext = $this->createHookContext(
            eventTypes: [RunEventTypeEnum::ContextCompactionStarted->value],
        );
        $this->subscriber->handleAfterTurnCommit($lifecycleContext);
        self::assertCount(0, $this->commandBus->messages,
            'Lifecycle commit itself must not dispatch');

        // Step 2: a stable commit on a later turn — fresh eligible provider usage.
        $stableContext = $this->createHookContext();
        $this->subscriber->handleAfterTurnCommit($stableContext);

        // On HEAD: compactionResolved prevents dispatch → 0 messages.
        // After fix: event-log eligibility allows dispatch → 1 message.
        self::assertCount(1, $this->commandBus->messages,
            'Later turn with fresh eligible provider usage MUST dispatch auto-compaction. '
            .'compactionResolved on HEAD permanently blocks this.');
        self::assertSame('auto', $this->commandBus->messages[0]->trigger);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Test: AgentCommandQueued / AgentCommandApplied guards (session 9)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Thesis: commits containing AgentCommandQueued must NOT dispatch
     * auto-compaction, even when provider usage exceeds threshold.
     *
     * AgentCommandQueued commits have effectsCount=0 but the command
     * has not yet been applied.  Dispatching auto-compaction here races
     * with the pending ApplyCommand → AdvanceRun → ExecuteLlmStep chain
     * and can dead-end the turn (session 9: compaction won the race,
     * continueAfterCompaction=false, user turn never resumed).
     */
    public function testSkipsWhenAgentCommandQueuedEventPresent(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $runState = $this->createRunState([
            $this->makeTextMessage('user', 'Hello'),
        ]);
        $this->runStore->method('get')->willReturn($runState);

        // Provider usage exceeds threshold — the hook WOULD dispatch
        // auto-compaction if not for the AgentCommandQueued guard.
        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]); // 12000 > 11000

        $context = $this->createHookContext(
            eventTypes: [RunEventTypeEnum::AgentCommandQueued->value],
            effectsCount: 0,
        );
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages,
            'AgentCommandQueued commit must NOT dispatch CompactRun '
            .'even when provider usage exceeds threshold (effectsCount=0). '
            .'The pending follow_up command must not be raced by compaction.');
    }

    /**
     * Thesis: commits containing AgentCommandApplied must NOT dispatch
     * auto-compaction, even when provider usage exceeds threshold.
     *
     * Mirror of testSkipsWhenAgentCommandQueuedEventPresent for the
     * applied side of the user-command lifecycle.  AgentCommandApplied
     * commits have effectsCount=0 but may schedule AdvanceRun via
     * postCommit callbacks; dispatching auto-compaction here would
     * race the pending command processing (session 9 class).
     */
    public function testSkipsWhenAgentCommandAppliedEventPresent(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $runState = $this->createRunState([
            $this->makeTextMessage('user', 'Hello'),
        ]);
        $this->runStore->method('get')->willReturn($runState);

        // Provider usage exceeds threshold — the hook WOULD dispatch
        // auto-compaction if not for the AgentCommandApplied guard.
        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]); // 12000 > 11000

        $context = $this->createHookContext(
            eventTypes: [RunEventTypeEnum::AgentCommandApplied->value],
            effectsCount: 0,
        );
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages,
            'AgentCommandApplied commit must NOT dispatch CompactRun '
            .'even when provider usage exceeds threshold (effectsCount=0). '
            .'The pending follow_up/steer command must not be raced by compaction.');
    }

    /**
     * Thesis: when the compaction preparation would summarize ONLY prior
     * compact_summary messages (no fresh non-summary conversation), the
     * auto-compaction hook must NOT dispatch CompactRun.
     *
     * Session 14: seq149/150 compacted only the prior compact_summary
     * (messages_to_summarize=1, prior_summary_present=true) producing a
     * near-zero token reduction visible to the user as redundant noise.
     * The auto hook must silently skip and wait for later turns.
     */
    public function testSkipsWhenCompactionWouldSummarizeOnlyCompactSummary(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $compactSummaryMsg = $this->makeCompactSummaryMessage();
        $freshUserMsg = $this->makeTextMessage('user', 'Hello');
        $freshAssistantMsg = $this->makeTextMessage('assistant', 'Hi there!');

        $messages = [$compactSummaryMsg, $freshUserMsg, $freshAssistantMsg];
        $runState = $this->createRunState($messages);

        $this->runStore->method('get')->willReturn($runState);

        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]);

        // Preparation: only the compact_summary message is summarized.
        // The fresh messages are in the retained tail.
        // Use anonymous class to guarantee the exact return value
        $summaryOnlyService = new class($compactSummaryMsg, $freshUserMsg, $freshAssistantMsg) implements CompactionServiceInterface {
            public function __construct(
                private readonly AgentMessage $compactSummaryMsg,
                private readonly AgentMessage $freshUserMsg,
                private readonly AgentMessage $freshAssistantMsg,
            ) {}
            public function prepare(array $messages): CompactionPrepareResult {
                return CompactionPrepareResult::ready(
                    messagesToSummarize: [$this->compactSummaryMsg],
                    retainedTailMessages: [$this->freshUserMsg, $this->freshAssistantMsg],
                    tokenEstimateBefore: 15000,
                    messagesCompacted: 1,
                    messagesRetained: 2,
                    firstRetainedIndex: 1,
                    priorSummaryPresent: true,
                );
            }
            public function buildSummarizationMessages(CompactionPrepareResult $result, ?string $customInstructions): array { return []; }
            public function buildCompactedMessages(string $summaryText, CompactionPrepareResult $result): \Ineersa\AgentCore\Contract\Compaction\CompactResult { throw new \RuntimeException('not called'); }
        };

        $summaryOnlySubscriber = new AutoCompactionHookSubscriber(
            $this->runStore,
            $this->providerUsageResolver,
            $this->compactionConfig,
            $this->modelResolver,
            $this->commandBus,
            $summaryOnlyService,
        );

        $context = $this->createHookContext();
        $summaryOnlySubscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages,
            'Auto compaction must NOT dispatch when messagesToSummarize '
            .'contains only compact_summary messages (session 14 seq149 class).');
    }

    /**
     * Thesis: when compaction preparation includes prior compact_summary
     * PLUS fresh non-summary conversation messages, the auto-compaction
     * hook MUST dispatch CompactRun.
     *
     * Session 14 seq230 was a valid later re-compaction that included
     * prior summary plus 25+ fresh messages.  The summary-only guard
     * must NOT block this class of valid re-compaction.
     */
    public function testAllowsCompactionWhenSummarizeIncludesFreshNonSummaryMessages(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $compactSummaryMsg = $this->makeCompactSummaryMessage();
        $freshUserMsg = $this->makeTextMessage('user', 'Long conversation turn 2...');
        $freshAssistantMsg = $this->makeTextMessage('assistant', 'Response to turn 2.');
        $recentUserMsg = $this->makeTextMessage('user', 'Turn 3 short message.');
        $recentAssistantMsg = $this->makeTextMessage('assistant', 'Turn 3 response.');

        $messages = [$compactSummaryMsg, $freshUserMsg, $freshAssistantMsg, $recentUserMsg, $recentAssistantMsg];
        $runState = $this->createRunState($messages);

        $this->runStore->method('get')->willReturn($runState);

        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]);

        // Use anonymous class to guarantee the exact return value
        $summaryPlusFreshService = new class($compactSummaryMsg, $freshUserMsg, $freshAssistantMsg, $recentUserMsg, $recentAssistantMsg) implements CompactionServiceInterface {
            public function __construct(
                private readonly AgentMessage $compactSummaryMsg,
                private readonly AgentMessage $freshUserMsg,
                private readonly AgentMessage $freshAssistantMsg,
                private readonly AgentMessage $recentUserMsg,
                private readonly AgentMessage $recentAssistantMsg,
            ) {}
            public function prepare(array $messages): CompactionPrepareResult {
                return CompactionPrepareResult::ready(
                    messagesToSummarize: [$this->compactSummaryMsg, $this->freshUserMsg, $this->freshAssistantMsg],
                    retainedTailMessages: [$this->recentUserMsg, $this->recentAssistantMsg],
                    tokenEstimateBefore: 20000,
                    messagesCompacted: 3,
                    messagesRetained: 2,
                    firstRetainedIndex: 3,
                    priorSummaryPresent: true,
                );
            }
            public function buildSummarizationMessages(CompactionPrepareResult $result, ?string $customInstructions): array { return []; }
            public function buildCompactedMessages(string $summaryText, CompactionPrepareResult $result): CompactResult { throw new \RuntimeException('not called'); }
        };

        $summaryPlusFreshSubscriber = new AutoCompactionHookSubscriber(
            $this->runStore,
            $this->providerUsageResolver,
            $this->compactionConfig,
            $this->modelResolver,
            $this->commandBus,
            $summaryPlusFreshService,
        );

        $context = $this->createHookContext();
        $summaryPlusFreshSubscriber->handleAfterTurnCommit($context);

        self::assertCount(1, $this->commandBus->messages,
            'Auto compaction MUST dispatch when messagesToSummarize includes '
            .'fresh non-summary messages alongside prior compact_summary '
            .'(session 14 seq230 class).');
        self::assertSame('auto', $this->commandBus->messages[0]->trigger);
    }

    /**
     * Thesis: when compaction preparation is not ready (structural skip),
     * the auto-compaction hook must NOT dispatch CompactRun nor emit a
     * visible failure — it silently skips, same as all other guards.
     */
    public function testSkipsWhenPreparationIsNotReady(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $messages = [
            $this->makeTextMessage('user', 'Hello'),
            $this->makeTextMessage('assistant', 'Hi!'),
        ];
        $runState = $this->createRunState($messages);

        $this->runStore->method('get')->willReturn($runState);

        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]);

        // Use anonymous class to guarantee the exact return value
        $failedService = new class implements CompactionServiceInterface {
            public function prepare(array $messages): CompactionPrepareResult {
                return CompactionPrepareResult::failed('too_few_messages');
            }
            public function buildSummarizationMessages(CompactionPrepareResult $result, ?string $customInstructions): array { return []; }
            public function buildCompactedMessages(string $summaryText, CompactionPrepareResult $result): CompactResult { throw new \RuntimeException('not called'); }
        };

        $failedSubscriber = new AutoCompactionHookSubscriber(
            $this->runStore,
            $this->providerUsageResolver,
            $this->compactionConfig,
            $this->modelResolver,
            $this->commandBus,
            $failedService,
        );

        $context = $this->createHookContext();
        $failedSubscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages,
            'Auto compaction must silently skip when preparation is not ready.');
    }

    /**
     * Thesis: when RunState is absent from the store, the auto-compaction
     * hook must NOT dispatch CompactRun and must NOT fatal.
     *
     * Under normal operation RunState always exists for runs processed
     * by after-turn hooks, but this is defensive against concurrent
     * store eviction, corruption, or edge cases where the hook fires
     * for a run whose state has been removed.
     */
    public function testSkipsWhenRunStateMissingBeforePreparation(): void
    {
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        // RunState absent: no stubbed return from runStore->get()
        // Under #[AllowMockObjectsWithoutExpectations] this returns null.

        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]); // 12000 > 11000

        $context = $this->createHookContext();
        $this->subscriber->handleAfterTurnCommit($context);

        self::assertCount(0, $this->commandBus->messages,
            'Must skip without fatal when RunState is missing from store '
            .'— null $runState must not dereference in prepare().');
    }
}
