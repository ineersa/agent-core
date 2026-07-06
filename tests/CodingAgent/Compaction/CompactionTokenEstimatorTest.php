<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Compaction\CompactionTokenEstimator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompactionTokenEstimator::class)]
final class CompactionTokenEstimatorTest extends TestCase
{
    private CompactionTokenEstimator $estimator;

    protected function setUp(): void
    {
        $this->estimator = new CompactionTokenEstimator();
    }

    /**
     * Thesis: estimateTokens counts only model-facing text, not JSON/metadata.
     */
    public function testEstimateTokensIsTextOnly(): void
    {
        $msg = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'hello world']],
            metadata: ['compact_summary' => true, 'large_key' => str_repeat('x', 1000)],
        );

        $tokens = $this->estimator->estimateTokens([$msg]);

        // ~11 chars for "hello world" → ceil(11/3.25) = 4 tokens
        // If JSON was included, it would be hundreds.
        $this->assertLessThan(10, $tokens, 'Token estimate should be text-only, not JSON-envelope');
    }

    /**
     * Thesis: A custom-role message includes the [role] prefix in estimation.
     */
    public function testEstimateTokensCustomRole(): void
    {
        $msg = new AgentMessage(
            role: 'custom_role',
            content: [['type' => 'text', 'text' => 'hello']],
        );

        $tokens = $this->estimator->estimateTokens([$msg]);

        // '[custom_role] hello' ≈ 20 chars → ceil(20/3.25) ≈ 7
        $this->assertGreaterThan(3, $tokens, 'Custom role prefix adds to token estimate');
        $this->assertLessThan(15, $tokens);
    }

    /**
     * Thesis: A message with no text content estimates to 0 tokens.
     */
    public function testEstimateTokensEmptyContent(): void
    {
        $msg = new AgentMessage(
            role: 'assistant',
            content: [],
        );

        $tokens = $this->estimator->estimateTokens([$msg]);

        $this->assertSame(0, $tokens);
    }
}
