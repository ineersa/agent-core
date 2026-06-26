<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Compaction\ProviderContextUsageResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Compaction\ProviderContextUsageResolver
 *
 * Tests the event-log-eligible provider usage resolution.
 * The key invariant: a provider usage measurement is eligible for
 * auto-compaction at most once — a subsequent auto
 * context_compaction_started event (trigger=auto) marks the
 * measurement handled.  Only a newer provider measurement (higher
 * event seq) re-opens eligibility.
 */
#[AllowMockObjectsWithoutExpectations]
final class ProviderContextUsageResolverTest extends TestCase
{
    /** @var EventStoreInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventStore;
    private ProviderContextUsageResolver $resolver;

    protected function setUp(): void
    {
        $this->eventStore = $this->createMock(EventStoreInterface::class);
        $this->resolver = new ProviderContextUsageResolver($this->eventStore);
    }

    /**
     * Configure the mock to return events for allFor() calls.
     *
     * The resolver may call allFor() multiple times per method
     * (once for provider measurement, once for auto start lookup).
     */
    private function mockEvents(array $events): void
    {
        $this->eventStore->method('allFor')
            ->willReturn($events);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function makeLlmStepCompleted(int $seq, int $inputTokens): RunEvent
    {
        return new RunEvent(
            runId: 'run-1',
            seq: $seq,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 'step-' . $seq,
                'stop_reason' => 'stop',
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => 100,
                    'total_tokens' => $inputTokens + 100,
                ],
            ],
        );
    }

    private function makeLlmStepAborted(int $seq, int $inputTokens): RunEvent
    {
        return new RunEvent(
            runId: 'run-1',
            seq: $seq,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepAborted->value,
            payload: [
                'step_id' => 'step-' . $seq,
                'stop_reason' => 'aborted',
                'usage' => [
                    'input_tokens' => $inputTokens,
                ],
            ],
        );
    }

    private function makeAutoCompactionStarted(int $seq): RunEvent
    {
        return new RunEvent(
            runId: 'run-1',
            seq: $seq,
            turnNo: 1,
            type: RunEventTypeEnum::ContextCompactionStarted->value,
            payload: [
                'step_id' => 'compact-' . $seq,
                'trigger' => 'auto',
                'estimated_tokens' => 1000,
                'keep_recent_tokens' => 500,
                'messages_before' => 10,
                'messages_to_summarize' => 5,
                'messages_retained' => 5,
                'first_retained_index' => 5,
                'prior_summary_present' => false,
            ],
        );
    }

    private function makeManualCompactionStarted(int $seq): RunEvent
    {
        return new RunEvent(
            runId: 'run-1',
            seq: $seq,
            turnNo: 1,
            type: RunEventTypeEnum::ContextCompactionStarted->value,
            payload: [
                'step_id' => 'compact-manual-' . $seq,
                'trigger' => 'manual',
                'estimated_tokens' => 1000,
                'keep_recent_tokens' => 500,
                'messages_before' => 10,
                'messages_to_summarize' => 5,
                'messages_retained' => 5,
                'first_retained_index' => 5,
                'prior_summary_present' => false,
            ],
        );
    }

    // ── getLatestInputTokens (raw, no eligibility check) ──────────

    public function testGetLatestInputTokensReturnsTokensWhenProviderEventExists(): void
    {
        $this->mockEvents([$this->makeLlmStepCompleted(1, 30755)]);

        self::assertSame(30755, $this->resolver->getLatestInputTokens('run-1'));
    }

    public function testGetLatestInputTokensReturnsNullWhenNoProviderEvent(): void
    {
        $this->mockEvents([]);

        self::assertNull($this->resolver->getLatestInputTokens('run-1'));
    }

    public function testGetLatestInputTokensUsesPromptTokensFallback(): void
    {
        $event = new RunEvent(
            runId: 'run-1',
            seq: 1,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 'step-1',
                'usage' => [
                    'prompt_tokens' => 20000,
                    'completion_tokens' => 500,
                ],
            ],
        );

        $this->mockEvents([$event]);

        self::assertSame(20000, $this->resolver->getLatestInputTokens('run-1'));
    }

    // ── getLatestEligibleInputTokens (event-log authoritative) ────

    /**
     * Thesis: when provider usage seq > latest auto started seq, the
     * measurement IS eligible.  This is the normal case — the provider
     * just completed a step and no auto-compaction has acted on it yet.
     */
    public function testEligibleWhenNoAutoCompactionAttemptExists(): void
    {
        $this->mockEvents([$this->makeLlmStepCompleted(5, 30755)]);

        self::assertSame(30755, $this->resolver->getLatestEligibleInputTokens('run-1'));
    }

    /**
     * Thesis: when latest auto started seq is AFTER the latest provider
     * measurement, the measurement is INELIGIBLE.  This catches the
     * session 1/3 stale-measurement loop — after auto-compaction starts,
     * the old provider measurement must not re-trigger.
     */
    public function testIneligibleWhenAutoCompactionStartedAfterProviderMeasurement(): void
    {
        $this->mockEvents([
            $this->makeLlmStepCompleted(10, 30755),
            $this->makeAutoCompactionStarted(11),
        ]);

        self::assertNull($this->resolver->getLatestEligibleInputTokens('run-1'));
    }

    /**
     * Thesis: when auto started seq equals provider seq (unlikely but
     * defensive), the measurement is ineligible — an auto compaction
     * attempt matching or following the measurement marks it handled.
     */
    public function testIneligibleWhenAutoStartedAtSameSeqAsProviderMeasurement(): void
    {
        $this->mockEvents([
            $this->makeLlmStepCompleted(10, 30755),
            $this->makeAutoCompactionStarted(10),
        ]);

        self::assertNull($this->resolver->getLatestEligibleInputTokens('run-1'));
    }

    /**
     * Thesis: a newer provider measurement (higher seq than the latest
     * auto start) RE-OPENS eligibility.  After auto-compaction succeeds
     * or fails, the user sends a new message and the LLM produces a new
     * measurement — that newer measurement IS eligible.
     */
    public function testEligibleWhenNewerProviderMeasurementArrivesAfterAutoStart(): void
    {
        $this->mockEvents([
            $this->makeLlmStepCompleted(10, 30755),
            $this->makeAutoCompactionStarted(11),
            $this->makeLlmStepCompleted(20, 32660),
        ]);

        self::assertSame(32660, $this->resolver->getLatestEligibleInputTokens('run-1'));
    }

    /**
     * Thesis: manual /compact starts (trigger=manual) do NOT mark the
     * provider measurement as handled.  Manual compaction is independent
     * of auto-compaction eligibility.
     */
    public function testManualCompactionStartDoesNotBlockEligibility(): void
    {
        $this->mockEvents([
            $this->makeLlmStepCompleted(10, 30755),
            $this->makeManualCompactionStarted(11),
        ]);

        // Manual start at seq 11 does NOT block provider measurement at seq 10.
        self::assertSame(30755, $this->resolver->getLatestEligibleInputTokens('run-1'));
    }

    /**
     * Thesis: in-flight auto compaction (started but not yet completed/
     * failed) marks the measurement handled.  We use context_compaction_started,
     * not completed/failed, because the in-flight attempt should prevent
     * duplicate dispatches.
     */
    public function testInFlightAutoCompactionMarksMeasurementHandled(): void
    {
        $this->mockEvents([
            $this->makeLlmStepCompleted(10, 30755),
            $this->makeAutoCompactionStarted(12),
        ]);

        // In-flight auto at seq 12 covers provider measurement at seq 10.
        self::assertNull($this->resolver->getLatestEligibleInputTokens('run-1'));
    }

    /**
     * Thesis: when auto compaction fails (context_compaction_failed), the
     * original started event still marks the provider measurement handled.
     * The system should NOT retry compaction for the SAME measurement on
     * failure — it should wait for a newer provider measurement.
     */
    public function testFailedAutoCompactionDoesNotReopenEligibility(): void
    {
        $this->mockEvents([
            $this->makeLlmStepCompleted(10, 30755),
            $this->makeAutoCompactionStarted(11),
            new RunEvent(
                runId: 'run-1',
                seq: 12,
                turnNo: 1,
                type: RunEventTypeEnum::ContextCompactionFailed->value,
                payload: [
                    'reason' => 'model_error',
                    'trigger' => 'auto',
                    'step_id' => 'compact-11',
                ],
            ),
        ]);

        // Failed auto start at seq 11 still covers provider measurement at seq 10.
        self::assertNull($this->resolver->getLatestEligibleInputTokens('run-1'));
    }

    /**
     * Thesis: after auto compaction SUCCEEDS AND a newer LLM step runs,
     * the newer measurement is eligible.
     */
    public function testEligibleAfterSuccessfulCompactionAndNewLlmStep(): void
    {
        $this->mockEvents([
            $this->makeLlmStepCompleted(10, 30755),
            $this->makeAutoCompactionStarted(11),
            new RunEvent(
                runId: 'run-1',
                seq: 12,
                turnNo: 1,
                type: RunEventTypeEnum::ContextCompacted->value,
                payload: ['trigger' => 'auto', 'estimated_tokens_before' => 30755, 'estimated_tokens_after' => 26739],
            ),
            $this->makeLlmStepCompleted(20, 32660),
        ]);

        // Older measurement at seq 10 was handled by auto start at 11.
        // Newer measurement at seq 20 is eligible.
        self::assertSame(32660, $this->resolver->getLatestEligibleInputTokens('run-1'));
    }

    /**
     * Thesis: llm_step_aborted events also count as provider measurements.
     */
    public function testLlmStepAbortedCountsAsProviderMeasurement(): void
    {
        $this->mockEvents([$this->makeLlmStepAborted(5, 15000)]);

        self::assertSame(15000, $this->resolver->getLatestEligibleInputTokens('run-1'));
    }

    // ── Structural failure-only marker tests (session 3 class) ─────

    /**
     * Thesis: when a provider measurement is followed by an auto
     * context_compaction_failed WITHOUT a preceding started event
     * (the prepare-failure path — e.g. too_few_messages,
     * no_safe_boundary), the failed event still marks the provider
     * measurement handled.  This is the session 3 seq79→seq87 class.
     *
     * Without this, d11039e0f allowed retry loops: the resolver only
     * checked context_compaction_started, but the prepare-failure
     * path emits only context_compaction_failed.
     */
    public function testIneligibleWhenAutoCompactionFailedWithoutStartedAfterProviderMeasurement(): void
    {
        $this->mockEvents([
            $this->makeLlmStepCompleted(74, 32660),
            new RunEvent(
                runId: 'run-1',
                seq: 79,
                turnNo: 1,
                type: RunEventTypeEnum::ContextCompactionFailed->value,
                payload: [
                    'reason' => 'no_safe_boundary',
                    'trigger' => 'auto',
                    'step_id' => null, // no step_id — prepare failure
                    'messages_replaced' => false,
                ],
            ),
        ]);

        // Failed-only at seq 79 covers provider measurement at seq 74.
        self::assertNull($this->resolver->getLatestEligibleInputTokens('run-1'));
    }

    /**
     * Thesis: after a prepare-failure-only marker, a newer provider
     * measurement (higher seq) re-opens eligibility.
     */
    public function testEligibleWhenNewerMeasurementAfterFailureOnlyMarker(): void
    {
        $this->mockEvents([
            $this->makeLlmStepCompleted(74, 32660),
            new RunEvent(
                runId: 'run-1',
                seq: 79,
                turnNo: 1,
                type: RunEventTypeEnum::ContextCompactionFailed->value,
                payload: [
                    'reason' => 'no_safe_boundary',
                    'trigger' => 'auto',
                ],
            ),
            // Newer provider measurement at seq 90.
            new RunEvent(
                runId: 'run-1',
                seq: 90,
                turnNo: 2,
                type: RunEventTypeEnum::LlmStepCompleted->value,
                payload: [
                    'step_id' => 'step-90',
                    'stop_reason' => 'stop',
                    'usage' => [
                        'input_tokens' => 35000,
                        'output_tokens' => 100,
                        'total_tokens' => 35100,
                    ],
                ],
            ),
        ]);

        // Newer measurement at seq 90 IS eligible — after failure-only marker at seq 79.
        self::assertSame(35000, $this->resolver->getLatestEligibleInputTokens('run-1'));
    }

    /**
     * Thesis: when both a started AND a failed event exist for the
     * same auto attempt (the normal LLM-path failure), the max seq
     * among them (failed) marks the measurement handled.
     */
    public function testIneligibleWhenStartedAndFailedBothExistAfterProviderMeasurement(): void
    {
        $this->mockEvents([
            $this->makeLlmStepCompleted(10, 30755),
            $this->makeAutoCompactionStarted(11),
            new RunEvent(
                runId: 'run-1',
                seq: 12,
                turnNo: 1,
                type: RunEventTypeEnum::ContextCompactionFailed->value,
                payload: [
                    'reason' => 'model_error',
                    'trigger' => 'auto',
                    'step_id' => 'compact-11',
                ],
            ),
        ]);

        // Both started (11) and failed (12) cover measurement at 10.
        self::assertNull($this->resolver->getLatestEligibleInputTokens('run-1'));
    }

    /**
     * Thesis: manual compaction failure does NOT count as an auto
     * attempt marker — only auto-triggered events count.
     */
    public function testManualCompactionFailureDoesNotBlockEligibility(): void
    {
        $this->mockEvents([
            $this->makeLlmStepCompleted(10, 30755),
            new RunEvent(
                runId: 'run-1',
                seq: 11,
                turnNo: 1,
                type: RunEventTypeEnum::ContextCompactionFailed->value,
                payload: [
                    'reason' => 'no_safe_boundary',
                    'trigger' => 'manual',
                ],
            ),
        ]);

        // Manual failure does NOT block auto eligibility.
        self::assertSame(30755, $this->resolver->getLatestEligibleInputTokens('run-1'));
    }

    /**
     * Thesis: usage with zero input_tokens is not a valid measurement.
     */
    public function testZeroTokensIgnored(): void
    {
        $event = new RunEvent(
            runId: 'run-1',
            seq: 1,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 'step-1',
                'usage' => [
                    'input_tokens' => 0,
                    'output_tokens' => 100,
                ],
            ],
        );

        $this->mockEvents([$event]);

        self::assertNull($this->resolver->getLatestInputTokens('run-1'));
        self::assertNull($this->resolver->getLatestEligibleInputTokens('run-1'));
    }
}
