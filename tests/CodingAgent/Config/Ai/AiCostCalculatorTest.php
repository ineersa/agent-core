<?php

declare(strict_types=1);

namespace Ineersa\Tests\CodingAgent\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiCost;
use Ineersa\CodingAgent\Config\Ai\AiCostCalculator;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Config\Ai\AiCostCalculator
 */
final class AiCostCalculatorTest extends TestCase
{
    private HatfieldModelCatalog $catalog;
    private AiCostCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        // Build a minimal catalog with a single priced model.
        $modelDef = new AiModelDefinition(
            id: 'anthropic/claude-sonnet-4',
            cost: new AiCost(input: 3.0, output: 15.0, cacheRead: 3.75, cacheWrite: 6.0),
        );

        $providerConfig = new \Ineersa\CodingAgent\Config\Ai\AiProviderConfig(
            id: 'anthropic',
            enabled: true,
            models: ['claude-sonnet-4' => $modelDef],
        );

        $aiConfig = new \Ineersa\CodingAgent\Config\Ai\AiConfig(
            providers: ['anthropic' => $providerConfig],
        );

        $this->catalog = new HatfieldModelCatalog($aiConfig);
        $this->calculator = new AiCostCalculator($this->catalog);
    }

    public function testCalculateCostWithPricing(): void
    {
        $cost = $this->calculator->calculateCost('anthropic/claude-sonnet-4', [
            'input_tokens' => 1_000_000,
            'output_tokens' => 500_000,
        ]);

        // input: 1M × $3/M = $3.00
        // output: 500k × $15/M = $7.50
        // total: $10.50
        $this->assertEqualsWithDelta(10.50, $cost, 0.01);
    }

    public function testCalculateCostWithThinkingTokens(): void
    {
        // Thinking tokens billed at output rate.
        $cost = $this->calculator->calculateCost('anthropic/claude-sonnet-4', [
            'input_tokens' => 100_000,
            'output_tokens' => 50_000,
            'thinking_tokens' => 30_000,
        ]);

        // input: 100k × $3/M = $0.30
        // output + thinking: 80k × $15/M = $1.20
        // total: $1.50
        $this->assertEqualsWithDelta(1.50, $cost, 0.01);
    }

    public function testCalculateCostPartitionsCacheReadAndWrite(): void
    {
        $cost = $this->calculator->calculateCost('anthropic/claude-sonnet-4', [
            'input_tokens' => 1_000_000,
            'output_tokens' => 500_000,
            'cache_read_tokens' => 200_000,
            'cache_creation_tokens' => 100_000,
            // Legacy alias must not double-count when cache_read_tokens is present.
            'cached_tokens' => 200_000,
        ]);

        // Partition: total input includes read+write classes, each billed once.
        // uncached: (1_000_000 - 200_000 - 100_000) × $3/M = $2.10
        // cache read: 200_000 × $3.75/M = $0.75
        // cache write: 100_000 × $6/M = $0.60
        // output: 500_000 × $15/M = $7.50
        // total: $10.95
        // Old double-billing charged full input ($3.00) + read again ($0.75) = $11.25+
        $this->assertEqualsWithDelta(10.95, $cost, 0.01);
    }

    public function testCalculateCostFallsBackToCachedTokensAlias(): void
    {
        // When only the legacy/provider-normalized cached_tokens alias is present,
        // it is partitioned out of total input and billed once at cache_read price.
        $cost = $this->calculator->calculateCost('anthropic/claude-sonnet-4', [
            'input_tokens' => 1_000_000,
            'output_tokens' => 0,
            'cached_tokens' => 200_000,
        ]);

        // uncached: 800_000 × $3/M = $2.40
        // cache read (from cached_tokens): 200_000 × $3.75/M = $0.75
        // total: $3.15 (not $3.00 + $0.75 double-billing)
        $this->assertEqualsWithDelta(3.15, $cost, 0.01);
    }

    public function testCalculateCostTreatsAbsentAndZeroCacheWritesAsOrdinaryInput(): void
    {
        $absent = $this->calculator->calculateCost('anthropic/claude-sonnet-4', [
            'input_tokens' => 1_000_000,
            'output_tokens' => 0,
        ]);
        $zero = $this->calculator->calculateCost('anthropic/claude-sonnet-4', [
            'input_tokens' => 1_000_000,
            'output_tokens' => 0,
            'cache_creation_tokens' => 0,
        ]);

        $this->assertEqualsWithDelta(3.0, $absent, 0.01);
        $this->assertEqualsWithDelta(3.0, $zero, 0.01);
    }

    public function testCalculateCostPrefersCacheReadTokensOverCachedAlias(): void
    {
        $cost = $this->calculator->calculateCost('anthropic/claude-sonnet-4', [
            'input_tokens' => 1_000_000,
            'output_tokens' => 0,
            'cache_read_tokens' => 100_000,
            'cached_tokens' => 900_000,
            'cache_creation_tokens' => 50_000,
        ]);

        // uncached: 850_000 × $3/M = $2.55
        // cache read: 100_000 × $3.75/M = $0.375
        // cache write: 50_000 × $6/M = $0.30
        $this->assertEqualsWithDelta(3.225, $cost, 0.001);
    }

    public function testCalculateCostClampsMalformedCacheCountersToInput(): void
    {
        $cost = $this->calculator->calculateCost('anthropic/claude-sonnet-4', [
            'input_tokens' => 100_000,
            'output_tokens' => 0,
            'cache_read_tokens' => 150_000,
            'cache_creation_tokens' => 80_000,
        ]);

        // Read clamps to 100_000; write clamps to remaining 0.
        // cache read: 100_000 × $3.75/M = $0.375
        $this->assertEqualsWithDelta(0.375, $cost, 0.001);
    }

    public function testCalculateCostNoPricingReturnsZero(): void
    {
        $cost = $this->calculator->calculateCost('nonexistent/model', [
            'input_tokens' => 1_000_000,
            'output_tokens' => 500_000,
        ]);

        $this->assertSame(0.0, $cost);
    }

    public function testCalculateCostWithZeroTokens(): void
    {
        $cost = $this->calculator->calculateCost('anthropic/claude-sonnet-4', [
            'input_tokens' => 0,
            'output_tokens' => 0,
        ]);

        $this->assertSame(0.0, $cost);
    }

    public function testCalculateCostWithAllZeroPricing(): void
    {
        // Model with zero pricing on all fields should return 0.0
        $modelDef = new AiModelDefinition(
            id: 'test/free-model',
            cost: new AiCost(input: 0.0, output: 0.0, cacheRead: 0.0, cacheWrite: 0.0),
        );

        $providerConfig = new \Ineersa\CodingAgent\Config\Ai\AiProviderConfig(
            id: 'test',
            enabled: true,
            models: ['free-model' => $modelDef],
        );

        $aiConfig = new \Ineersa\CodingAgent\Config\Ai\AiConfig(
            providers: ['test' => $providerConfig],
        );

        $catalog = new HatfieldModelCatalog($aiConfig);
        $calculator = new AiCostCalculator($catalog);

        $cost = $calculator->calculateCost('test/free-model', [
            'input_tokens' => 1_000_000,
            'output_tokens' => 500_000,
        ]);

        $this->assertSame(0.0, $cost);
    }

    public function testCalculateCostWithEmptyUsage(): void
    {
        $cost = $this->calculator->calculateCost('anthropic/claude-sonnet-4', []);

        $this->assertSame(0.0, $cost);
    }
}
