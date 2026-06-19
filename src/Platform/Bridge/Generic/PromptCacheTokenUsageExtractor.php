<?php

declare(strict_types=1);

namespace Ineersa\Platform\Bridge\Generic;

use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Token usage extractor that normalises prompt-cache fields across
 * OpenAI-compatible providers (OpenAI, z.ai, DeepSeek).
 *
 * Handles both streaming (extractFromArray) and non-streaming (extract) paths.
 *
 * Provider field mapping:
 *   - OpenAI/z.ai Chat Completions: usage.prompt_tokens_details.cached_tokens
 *   - OpenAI Responses:             usage.input_tokens_details.cached_tokens
 *   - DeepSeek:                     usage.prompt_cache_hit_tokens
 *   - Generic (legacy):             usage.num_cached_tokens
 *
 * Cache-read tokens are the primary signal for the TUI footer's cache-hit
 * percentage display.  For providers that only report a single aggregate
 * cached-tokens field without distinguishing read vs creation, the aggregate
 * is treated as cache-read tokens (the more common case for in-scope providers).
 *
 * Cache-creation tokens are only populated from explicit provider fields
 * (cache_creation_tokens / cache_creation_input_tokens) and are not inferred.
 */
final class PromptCacheTokenUsageExtractor implements TokenUsageExtractorInterface
{
    public function extract(RawResultInterface $rawResult, array $options = []): ?TokenUsageInterface
    {
        if (($options['stream'] ?? false) === true) {
            return null;
        }

        $content = $rawResult->getData();

        if (!\array_key_exists('usage', $content)) {
            return null;
        }

        return $this->extractFromArray($content['usage']);
    }

    /**
     * Extract a {@see TokenUsage} from a raw usage array.
     *
     * @param array<string, mixed> $usage Raw usage payload from provider
     */
    public function extractFromArray(array $usage): TokenUsage
    {
        $promptTokens = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
        $completionTokens = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;
        $totalTokens = $usage['total_tokens'] ?? null;
        $thinkingTokens = $usage['completion_tokens_details']['reasoning_tokens']
            ?? $usage['output_tokens_details']['reasoning_tokens']
            ?? null;

        // ── Cache-read tokens (prompt-cache hit) ──
        // For in-scope providers (OpenAI, z.ai, DeepSeek), this is the
        // number of input tokens served from the prompt cache on this
        // request.  The TUI footer uses this for the cache-hit percentage.
        $cacheRead = $usage['cache_read_tokens']
            ?? $usage['cache_read_input_tokens']
            ?? $usage['prompt_cache_hit_tokens']
            ?? (isset($usage['prompt_tokens_details']) && \is_array($usage['prompt_tokens_details'])
                ? ($usage['prompt_tokens_details']['cached_tokens'] ?? null)
                : null)
            ?? (isset($usage['input_tokens_details']) && \is_array($usage['input_tokens_details'])
                ? ($usage['input_tokens_details']['cached_tokens'] ?? null)
                : null)
            ?? null;

        // ── Cache tokens: aggregate cached field ──
        // The aggregate cachedTokens field covers both cache-read and
        // cache-creation tokens in providers that don't split them.
        $aggregateCached = $usage['num_cached_tokens']
            ?? $usage['cached_tokens']
            ?? null;

        // When no provider-specific cache-read field exists, fall back
        // to the aggregate cached count (treating all cached tokens as
        // cache-read for percentage display).
        if (null === $cacheRead && null !== $aggregateCached) {
            $cacheRead = $aggregateCached;
        }

        // Populate cachedTokens for cost calculation.  When only
        // provider-specific cache-hit fields exist (OpenAI/z.ai details,
        // DeepSeek prompt_cache_hit_tokens), treat them as the aggregate
        // cached count too so cost calculators that key off cached_tokens
        // continue to work.
        $effectiveCached = $aggregateCached ?? $cacheRead;

        // ── Cache-creation tokens ──
        // Only populated when the provider explicitly reports them.
        $cacheCreation = $usage['cache_creation_tokens']
            ?? $usage['cache_creation_input_tokens']
            ?? null;

        return new TokenUsage(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            thinkingTokens: $thinkingTokens,
            cachedTokens: $effectiveCached,
            cacheCreationTokens: $cacheCreation,
            cacheReadTokens: $cacheRead,
            totalTokens: $totalTokens,
        );
    }
}
