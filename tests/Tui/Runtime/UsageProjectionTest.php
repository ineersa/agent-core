<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Runtime\UsageProjection;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for UsageProjection accumulation and reset invariants.
 *
 * @covers \Ineersa\Tui\Runtime\UsageProjection
 */
final class UsageProjectionTest extends TestCase
{
    private UsageProjection $usage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usage = new UsageProjection();
    }

    // ── Reset ──

    public function testResetTurnResetsPerTurnFields(): void
    {
        // Pre-set non-zero values to verify they get reset
        $this->usage->turnOutputTokens = 1000;
        $this->usage->llmEndTime = 12345.0;
        $this->usage->latestInputTokens = 500;
        $this->usage->inputTokens = 999;
        $this->usage->outputTokens = 888;
        $this->usage->totalCost = 12.34;

        $this->usage->resetTurn();

        // Per-turn fields must be reset
        self::assertSame(0, $this->usage->turnOutputTokens);
        self::assertSame(0.0, $this->usage->llmEndTime);

        // latestInputTokens must be PRESERVED across turns to prevent the
        // context window percentage footer from flickering to 0% during
        // Working... between TurnStarted and the next response.
        self::assertSame(500, $this->usage->latestInputTokens);

        // turnStartTime must be set to a recent timestamp
        self::assertGreaterThan(0.0, $this->usage->turnStartTime);
        self::assertGreaterThan(time() - 10, $this->usage->turnStartTime);

        // Session-level fields must NOT be reset
        self::assertSame(999, $this->usage->inputTokens);
        self::assertSame(888, $this->usage->outputTokens);
        self::assertEqualsWithDelta(12.34, $this->usage->totalCost, 0.001);
    }

    // ── Accumulate ──

    public function testAccumulateWithFullUsage(): void
    {
        $event = $this->makeAssistantMessageCompletedEvent([
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'cost' => 0.0015,
            ],
        ]);

        $this->usage->accumulate($event);

        self::assertSame(100, $this->usage->latestInputTokens);
        self::assertSame(100, $this->usage->inputTokens);
        self::assertSame(50, $this->usage->outputTokens);
        self::assertSame(50, $this->usage->turnOutputTokens);
        self::assertEqualsWithDelta(0.0015, $this->usage->totalCost, 0.00001);
        self::assertGreaterThan(0.0, $this->usage->llmEndTime);
    }

    public function testAccumulateWithFallbackKeys(): void
    {
        $event = $this->makeAssistantMessageCompletedEvent([
            'usage' => [
                'prompt_tokens' => 200,
                'completion_tokens' => 75,
                'total_cost' => 0.0025,
            ],
        ]);

        $this->usage->accumulate($event);

        self::assertSame(200, $this->usage->latestInputTokens);
        self::assertSame(200, $this->usage->inputTokens);
        self::assertSame(75, $this->usage->outputTokens);
        self::assertSame(75, $this->usage->turnOutputTokens);
        self::assertEqualsWithDelta(0.0025, $this->usage->totalCost, 0.00001);
    }

    public function testAccumulateWithMissingUsage(): void
    {
        $event = $this->makeAssistantMessageCompletedEvent([]);

        // Should not crash or modify defaults
        $this->usage->accumulate($event);

        self::assertSame(0, $this->usage->latestInputTokens);
        self::assertSame(0, $this->usage->inputTokens);
        self::assertSame(0, $this->usage->outputTokens);
        self::assertSame(0, $this->usage->turnOutputTokens);
        self::assertEqualsWithDelta(0.0, $this->usage->totalCost, 0.001);
        self::assertGreaterThan(0.0, $this->usage->llmEndTime);
    }

    public function testAccumulateIgnoresNonAssistantMessageCompleted(): void
    {
        // Pre-set values so we can verify they don't change
        $this->usage->inputTokens = 100;

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnStarted->value,
            runId: 'test',
            seq: 1,
            payload: ['usage' => ['input_tokens' => 999]],
        );

        $this->usage->accumulate($event);

        // Must not change anything
        self::assertSame(100, $this->usage->inputTokens);
        self::assertSame(0, $this->usage->latestInputTokens);
        self::assertSame(0.0, $this->usage->llmEndTime);
    }

    // ── Turn lifecycle ──

    public function testAccumulateAcrossTurns(): void
    {
        // Turn 1: accumulate two events
        $event1 = $this->makeAssistantMessageCompletedEvent([
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'cost' => 0.001],
        ]);
        $event2 = $this->makeAssistantMessageCompletedEvent([
            'usage' => ['input_tokens' => 100, 'output_tokens' => 30, 'cost' => 0.0005],
        ]);

        $this->usage->accumulate($event1);
        $this->usage->accumulate($event2);

        // Session-level: accumulated across both events
        self::assertSame(200, $this->usage->inputTokens);
        self::assertSame(80, $this->usage->outputTokens);
        self::assertEqualsWithDelta(0.0015, $this->usage->totalCost, 0.00001);
        // Per-turn: accumulated within turn
        self::assertSame(80, $this->usage->turnOutputTokens);

        // Reset for turn 2
        $this->usage->resetTurn();

        // Session-level still accumulated
        self::assertSame(200, $this->usage->inputTokens);
        self::assertSame(80, $this->usage->outputTokens);

        // Per-turn fields reset
        self::assertSame(0, $this->usage->turnOutputTokens);

        // Turn 2: accumulate one event
        $event3 = $this->makeAssistantMessageCompletedEvent([
            'usage' => ['input_tokens' => 50, 'output_tokens' => 20, 'cost' => 0.0003],
        ]);
        $this->usage->accumulate($event3);

        // Session-level continues accumulating
        self::assertSame(250, $this->usage->inputTokens);
        self::assertSame(100, $this->usage->outputTokens);
        self::assertEqualsWithDelta(0.0018, $this->usage->totalCost, 0.00001);
        // Per-turn reflects only turn 2
        self::assertSame(20, $this->usage->turnOutputTokens);
    }

    // ── latestInputTokens preservation across turns ──

    public function testLatestInputTokensPreservedAcrossReset(): void
    {
        // Simulate turn 1: accumulate sets latestInputTokens
        $event1 = $this->makeAssistantMessageCompletedEvent([
            'usage' => ['input_tokens' => 300, 'output_tokens' => 100],
        ]);
        $this->usage->accumulate($event1);
        self::assertSame(300, $this->usage->latestInputTokens);

        // Turn 2 starts: reset per-turn fields but PRESERVE latestInputTokens
        $this->usage->resetTurn();
        self::assertSame(300, $this->usage->latestInputTokens, 'latestInputTokens must survive reset so context % footer does not flicker to 0% during Working');

        // Turn 2 completes: fresh usage overwrites the preserved value
        $event2 = $this->makeAssistantMessageCompletedEvent([
            'usage' => ['input_tokens' => 500, 'output_tokens' => 50],
        ]);
        $this->usage->accumulate($event2);
        self::assertSame(500, $this->usage->latestInputTokens, 'Fresh turn usage must replace the carried-forward value');
    }

    // ── Cost edge cases ──

    public function testCostWithIntValue(): void
    {
        $event = $this->makeAssistantMessageCompletedEvent([
            'usage' => ['cost' => 5],
        ]);

        $this->usage->accumulate($event);

        self::assertSame(5.0, $this->usage->totalCost);
    }

    public function testCostWithNullValue(): void
    {
        $event = $this->makeAssistantMessageCompletedEvent([
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        $this->usage->accumulate($event);

        // Cost should remain 0
        self::assertEqualsWithDelta(0.0, $this->usage->totalCost, 0.001);
    }

    public function testZeroOutputTokens(): void
    {
        $event = $this->makeAssistantMessageCompletedEvent([
            'usage' => ['input_tokens' => 10, 'output_tokens' => 0, 'cost' => 0.001],
        ]);

        $this->usage->accumulate($event);

        self::assertSame(10, $this->usage->inputTokens);
        self::assertSame(0, $this->usage->outputTokens);
        self::assertSame(0, $this->usage->turnOutputTokens);
    }

    // ── Helpers ──

    private function makeAssistantMessageCompletedEvent(array $payload): RuntimeEvent
    {
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::AssistantMessageCompleted->value,
            runId: 'test-run',
            seq: 1,
            payload: $payload,
        );
    }
}
