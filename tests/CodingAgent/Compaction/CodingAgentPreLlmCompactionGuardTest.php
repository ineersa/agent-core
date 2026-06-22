<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\AgentCore\Contract\Compaction\PreLlmCompactionGuardInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Compaction\ActiveModelResolverInterface;
use Ineersa\CodingAgent\Compaction\CodingAgentPreLlmCompactionGuard;
use Ineersa\CodingAgent\Compaction\CompactionTokenEstimator;
use Ineersa\CodingAgent\Compaction\ProviderContextUsageResolver;
use Ineersa\CodingAgent\Config\CompactionConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Compaction\CodingAgentPreLlmCompactionGuard
 *
 * Pre-LLM compaction guard now uses provider-reported usage (from
 * llm_step_completed events) as the authoritative context size.
 * The text-only CompactionTokenEstimator is no longer the trigger
 * baseline.
 */
#[AllowMockObjectsWithoutExpectations]
final class CodingAgentPreLlmCompactionGuardTest extends TestCase
{
    private CodingAgentPreLlmCompactionGuard $guard;
    /** @var EventStoreInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventStore;
    private ProviderContextUsageResolver $providerUsageResolver;
    private CompactionConfig $compactionConfig;
    /** @var ActiveModelResolverInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $modelResolver;

    protected function setUp(): void
    {
        $this->eventStore = $this->createMock(EventStoreInterface::class);
        $this->providerUsageResolver = new ProviderContextUsageResolver(
            $this->eventStore,
            new CompactionTokenEstimator(),
        );
        $this->compactionConfig = new CompactionConfig(
            autoEnabled: true,
            compactAfterTokens: 11000,
        );
        $this->modelResolver = $this->createMock(ActiveModelResolverInterface::class);
        // Most tests don't care about the model; return null by default.
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $this->guard = new CodingAgentPreLlmCompactionGuard(
            $this->compactionConfig,
            $this->providerUsageResolver,
            $this->modelResolver,
        );
    }

    private function makeTextMessage(string $role, string $text): AgentMessage
    {
        return AgentMessage::fromPayload([
            'content' => [['text' => $text]],
            'role' => $role,
        ]);
    }

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

    /**
     * Create a llm_step_completed event WITH assistant_message payload
     * so the resolver can compute delta.
     */
    private function makeLlmStepCompletedEventWithAssistant(
        int $inputTokens,
        string $assistantText = 'Hello from assistant',
    ): RunEvent {
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
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => $assistantText]],
                ],
            ],
        );
    }

    // ── Provider-usage-based trigger tests ──────────────────────────

    /**
     * Thesis: provider usage exceeds threshold → return true,
     * even though the text-only estimator would say otherwise
     * (the messages array here is tiny, estimator would give ~2 tokens).
     */
    public function testReturnsTrueWhenProviderUsageExceedsThreshold(): void
    {
        $messages = [
            $this->makeTextMessage('user', 'Hello'), // text estimator ≈ 2 tokens, well below 11000
        ];

        $this->eventStore->expects(self::once())
            ->method('allFor')
            ->with('run-1')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]); // 12000 > 11000

        self::assertTrue(
            $this->guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, null),
        );
    }

    /**
     * Thesis: provider usage below threshold → return false,
     * even though the text-only estimator for very long messages
     * might say otherwise.
     */
    public function testReturnsFalseWhenProviderUsageBelowThreshold(): void
    {
        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 50000)), // estimator ≈ 15384 > 11000
        ];

        $this->eventStore->expects(self::once())
            ->method('allFor')
            ->with('run-1')
            ->willReturn([$this->makeLlmStepCompletedEvent(5000)]); // 5000 < 11000

        self::assertFalse(
            $this->guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, null),
        );
    }

    /**
     * Thesis: no provider measurement → no auto-compaction.
     * The text-only estimator is NOT a fallback trigger baseline.
     */
    public function testReturnsFalseWhenNoProviderUsageExists(): void
    {
        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 50000)), // would exceed if we used estimator
        ];

        $this->eventStore->expects(self::once())
            ->method('allFor')
            ->with('run-1')
            ->willReturn([]); // No llm_step_completed events at all

        self::assertFalse(
            $this->guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, null),
        );
    }

    // ── Preserved guard tests (unchanged semantics) ─────────────────

    public function testReturnsFalseWhenAutoDisabled(): void
    {
        $disabledConfig = new CompactionConfig(autoEnabled: false, compactAfterTokens: 1);
        $guard = new CodingAgentPreLlmCompactionGuard(
            $disabledConfig,
            $this->providerUsageResolver,
            $this->modelResolver,
        );

        $messages = [
            $this->makeTextMessage('user', 'Hello'),
        ];

        self::assertFalse(
            $guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, null),
        );
    }

    public function testReturnsFalseWhenCompactionInFlight(): void
    {
        $messages = [
            $this->makeTextMessage('user', 'Hello'),
        ];

        // In-flight guard catches before event store is queried.
        self::assertFalse(
            $this->guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, 'compact-1234567890'),
        );
    }

    public function testRespectsModelOverrides(): void
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
            ->with('run-1')
            ->willReturn('openai/gpt-4');

        $guard = new CodingAgentPreLlmCompactionGuard(
            $configWithOverride,
            $this->providerUsageResolver,
            $modelResolver,
        );

        $messages = [
            $this->makeTextMessage('user', 'Hello'),
        ];

        $this->eventStore->expects(self::once())
            ->method('allFor')
            ->with('run-1')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]); // 12000 < 50000 override

        self::assertFalse(
            $guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, null),
        );
    }

    public function testImplementsInterface(): void
    {
        self::assertInstanceOf(PreLlmCompactionGuardInterface::class, $this->guard);
    }

    /**
     * Thesis: after the guard returns true once for a given run+turnNo,
     * subsequent calls with the same run+turnNo return false — preventing
     * infinite AdvanceRun → compact → AdvanceRun loops.
     */
    public function testOneShotDedupPreventsRepeatedCompactionForSameTurn(): void
    {
        $messages = [
            $this->makeTextMessage('user', 'Hello'),
        ];

        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]); // 12000 > 11000

        // First call → true (no dedup yet).
        self::assertTrue(
            $this->guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, null),
            'First call should trigger compaction',
        );

        // Second call with same run+turnNo → false (dedup hit).
        self::assertFalse(
            $this->guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, null),
            'Second call with same run+turnNo should be blocked by dedup',
        );
    }

    /**
     * Thesis: the dedup is keyed by (runId, turnNo); a different turnNo
     * is a fresh evaluation and should NOT be blocked.
     */
    public function testDedupIsPerTurnNo(): void
    {
        $messages = [
            $this->makeTextMessage('user', 'Hello'),
        ];

        $this->eventStore->method('allFor')
            ->willReturn([$this->makeLlmStepCompletedEvent(12000)]); // 12000 > 11000

        // Turn 1 → true.
        self::assertTrue(
            $this->guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, null),
        );

        // Same run, different turn → true (fresh evaluation).
        self::assertTrue(
            $this->guard->shouldCompactBeforeLlmStep('run-1', 2, $messages, null),
            'Different turnNo should not be blocked by dedup',
        );
    }

    // ── Delta-aware trigger tests ───────────────────────────────────

    /**
     * Thesis: provider input below threshold but post-measurement delta
     * (tool results, new user message) pushes effective tokens over
     * compact_after_tokens → return true.
     */
    public function testReturnsTrueWhenDeltaPushesEffectiveTokensOverThreshold(): void
    {
        $assistantText = 'Let me check that.';
        $messages = [
            $this->makeTextMessage('user', 'What is the answer?'),
            $this->makeTextMessage('assistant', $assistantText),
            $this->makeTextMessage('tool', str_repeat('tool result data ', 450)),
            $this->makeTextMessage('user', 'Now explain more.'),
        ];

        // input_tokens=9000 (below 11000) but delta from tool output pushes it over.
        $this->eventStore->expects(self::once())
            ->method('allFor')
            ->with('run-1')
            ->willReturn([$this->makeLlmStepCompletedEventWithAssistant(9000, $assistantText)]);

        self::assertTrue(
            $this->guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, null),
            'Should trigger when delta pushes effective tokens over threshold',
        );
    }

    /**
     * Thesis: huge pre-measurement messages with NO post-measurement
     * delta do NOT trigger auto-compaction — proves no full-conversation
     * estimator fallback.
     */
    public function testReturnsFalseWhenBelowThresholdAndNoDelta(): void
    {
        $assistantText = 'Done.';
        $messages = [
            $this->makeTextMessage('user', str_repeat('large pre-measurement text ', 500)),
            $this->makeTextMessage('assistant', $assistantText),
        ];

        // input_tokens=9000 (below 11000).  Huge user message was already
        // counted in input_tokens.  No delta → effective = 9000 ≤ 11000.
        $this->eventStore->expects(self::once())
            ->method('allFor')
            ->with('run-1')
            ->willReturn([$this->makeLlmStepCompletedEventWithAssistant(9000, $assistantText)]);

        self::assertFalse(
            $this->guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, null),
            'Should NOT trigger when below threshold with no delta',
        );
    }
}
