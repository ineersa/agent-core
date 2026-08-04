<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

use Ineersa\AgentCore\Domain\Model\CostCalculatorInterface;

/**
 * Computes LLM cost using Hatfield model catalog pricing.
 *
 * Bridged into AgentCore via CostCalculatorInterface so the
 * LlmPlatformAdapter can add cost to usage events without
 * depending on the CodingAgent layer.
 *
 * Pricing formula (per 1M tokens convention from AiCost).
 * Under the current OpenAI-compatible token convention, total
 * input_tokens already includes cache-read and cache-write classes,
 * so each class is partitioned and billed exactly once:
 *   cache_read  = max(0, cache_read_tokens ?? cached_tokens), clamped to input
 *   cache_write = max(0, cache_creation_tokens), clamped to remaining input
 *   uncached    = input - cache_read - cache_write
 *   cost = uncached / 1_000_000 * input_price
 *        + cache_read / 1_000_000 * cache_read_price
 *        + cache_write / 1_000_000 * cache_write_price
 *        + (output_tokens + thinking_tokens) / 1_000_000 * output_price
 *
 * Thinking tokens are billed at the output rate.
 *
 * If the model has no pricing configured (null or all-zero AiCost),
 * returns 0.0 which yields $0.00 in the TUI footer.
 */
final readonly class AiCostCalculator implements CostCalculatorInterface
{
    public function __construct(
        private HatfieldModelCatalog $catalog,
    ) {
    }

    public function calculateCost(string $modelRef, array $usage): float
    {
        $model = $this->catalog->getModel($modelRef);

        if (null === $model || null === $model->cost) {
            return 0.0;
        }

        $cost = $model->cost;

        // All-zero pricing is equivalent to "no pricing".
        if (0.0 === $cost->input && 0.0 === $cost->output && 0.0 === $cost->cacheRead && 0.0 === $cost->cacheWrite) {
            return 0.0;
        }

        $inputTokens = max(0, (int) ($usage['input_tokens'] ?? 0));
        $outputTokens = max(0, (int) ($usage['output_tokens'] ?? 0));
        $thinkingTokens = max(0, (int) ($usage['thinking_tokens'] ?? 0));

        $cacheReadTokens = \array_key_exists('cache_read_tokens', $usage)
            ? max(0, (int) $usage['cache_read_tokens'])
            : max(0, (int) ($usage['cached_tokens'] ?? 0));
        if ($cacheReadTokens > $inputTokens) {
            $cacheReadTokens = $inputTokens;
        }

        $cacheWriteTokens = max(0, (int) ($usage['cache_creation_tokens'] ?? 0));
        $remainingAfterRead = $inputTokens - $cacheReadTokens;
        if ($cacheWriteTokens > $remainingAfterRead) {
            $cacheWriteTokens = $remainingAfterRead;
        }

        $uncachedInputTokens = $inputTokens - $cacheReadTokens - $cacheWriteTokens;

        $total = 0.0;

        if ($uncachedInputTokens > 0 && $cost->input > 0.0) {
            $total += ($uncachedInputTokens / 1_000_000) * $cost->input;
        }

        if ($cacheReadTokens > 0 && $cost->cacheRead > 0.0) {
            $total += ($cacheReadTokens / 1_000_000) * $cost->cacheRead;
        }

        if ($cacheWriteTokens > 0 && $cost->cacheWrite > 0.0) {
            $total += ($cacheWriteTokens / 1_000_000) * $cost->cacheWrite;
        }

        // Output tokens: regular output + thinking (billed at output rate)
        $billableOutputTokens = $outputTokens + $thinkingTokens;
        if ($billableOutputTokens > 0 && $cost->output > 0.0) {
            $total += ($billableOutputTokens / 1_000_000) * $cost->output;
        }

        return $total;
    }
}
