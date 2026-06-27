<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

use Ineersa\AgentCore\Domain\Model\CostCalculatorInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Computes LLM cost using Hatfield model catalog pricing.
 *
 * Bridged into AgentCore via CostCalculatorInterface so the
 * LlmPlatformAdapter can add cost to usage events without
 * depending on the CodingAgent layer.
 *
 * Pricing formula (per 1M tokens convention from AiCost):
 *   cost = input_tokens / 1_000_000 * input_price
 *        + output_tokens / 1_000_000 * output_price
 *        + thinking_tokens / 1_000_000 * output_price (billed as output)
 *        + cached_tokens / 1_000_000 * cache_read_price
 *
 * Thinking tokens are billed at the output rate.
 * Cached tokens are billed at the cache-read rate (the only signal
 * available in standard API responses).  Cache-write attribution is
 * not supported without provider-specific metadata.
 *
 * If the model has no pricing configured (null or all-zero AiCost),
 * returns 0.0 which yields $0.00 in the TUI footer.
 */
final readonly class AiCostCalculator implements CostCalculatorInterface
{
    public function __construct(
        private HatfieldModelCatalog $catalog,
        private LoggerInterface $logger = new NullLogger(),
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

        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $thinkingTokens = (int) ($usage['thinking_tokens'] ?? 0);
        $cachedTokens = (int) ($usage['cached_tokens'] ?? 0);

        $total = 0.0;

        if ($inputTokens > 0 && $cost->input > 0.0) {
            $total += ($inputTokens / 1_000_000) * $cost->input;
        }

        // Output tokens: regular output + thinking (billed at output rate)
        $billableOutputTokens = $outputTokens + $thinkingTokens;
        if ($billableOutputTokens > 0 && $cost->output > 0.0) {
            $total += ($billableOutputTokens / 1_000_000) * $cost->output;
        }

        // Cached tokens billed at cache-read rate
        if ($cachedTokens > 0 && $cost->cacheRead > 0.0) {
            $total += ($cachedTokens / 1_000_000) * $cost->cacheRead;
        }

        // No cache-write attribution: standard API responses do not
        // differentiate cache writes from reads.

        if ($total > 0.0) {
        }

        return $total;
    }
}
