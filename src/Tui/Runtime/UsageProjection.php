<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

/**
 * Mutable token/cost/timing projection for the TUI footer.
 *
 * Holds both session-level accumulated metrics (inputTokens, outputTokens,
 * totalCost) and per-turn metrics (turnOutputTokens, turnStartTime,
 * llmEndTime, latestInputTokens). Per-turn fields are reset on each new
 * turn via resetTurn().
 *
 * Cache-read / cache-creation tokens are session-level accumulators that
 * survive resetTurn().  The cache-hit percentage is derived from
 * cache-read tokens divided by accumulated input tokens.
 *
 * Extracted from RuntimeEventPoller::extractFooterUsage().
 */
final class UsageProjection
{
    /** @var int Accumulated input tokens across the session. */
    public int $inputTokens = 0;

    /** @var int Accumulated output tokens across the session. */
    public int $outputTokens = 0;

    /** @var float Running cost estimate in dollars (accumulated from usage). */
    public float $totalCost = 0.0;

    /** @var float Timestamp when the LLM response completes (reset per-turn). */
    public float $llmEndTime = 0.0;

    /** @var int Output tokens generated in the current turn (accumulated per-turn). */
    public int $turnOutputTokens = 0;

    /** @var float Timestamp when the current turn started (set on TurnStarted). */
    public float $turnStartTime = 0.0;

    /** @var int Latest input_tokens from the most recent AssistantMessageCompleted (not accumulated). */
    public int $latestInputTokens = 0;

    /**
     * Accumulated cache-read tokens across the session (not reset per-turn).
     *
     * When a provider reports cache-read telemetry (either explicitly via
     * cache_read_tokens or via the aggregate cached_tokens field), these
     * tokens are accumulated here to compute the session-level cache-hit %.
     */
    public int $cacheReadTokens = 0;

    /**
     * Accumulated cache-creation tokens across the session (not reset per-turn).
     *
     * Only populated when the provider explicitly reports cache-creation
     * telemetry (cache_creation_tokens).
     */
    public int $cacheCreationTokens = 0;

    /**
     * Whether any cache-read telemetry has been seen this session.
     *
     * Set to true on the first {@see AssistantMessageCompleted} event
     * whose usage payload contains a cache_read_tokens (or cached_tokens
     * fallback) key with a non-null value.  Used by the footer to decide
     * whether to show the cache-hit segment: absent telemetry shows
     * nothing; reported zero cache-read tokens shows 0%.
     */
    public bool $hasCacheTelemetry = false;

    /**
     * Reset all per-turn metrics for a new turn.
     *
     * Called by the poller when a TurnStarted event arrives.
     * Session-level fields (inputTokens, outputTokens, totalCost,
     * cacheReadTokens, cacheCreationTokens) are NOT reset — they
     * accumulate across the entire session.
     */
    public function resetTurn(): void
    {
        $this->turnOutputTokens = 0;
        $this->turnStartTime = microtime(true);
        $this->llmEndTime = 0.0;
        // Preserve latestInputTokens from the previous turn so the context
        // window percentage footer does not flicker to 0% during Working...
        // between TurnStarted and the first AssistantMessageCompleted.
        // Fresh turn usage will overwrite this when it arrives.
        // cacheReadTokens, cacheCreationTokens, and hasCacheTelemetry
        // are session-level and intentionally NOT reset.
    }

    /**
     * Extract token usage and cost from an AssistantMessageCompleted event.
     *
     * @param RuntimeEvent $event The runtime event (only AssistantMessageCompleted is processed)
     */
    public function accumulate(RuntimeEvent $event): void
    {
        if (RuntimeEventTypeEnum::AssistantMessageCompleted->value !== $event->type) {
            return;
        }

        // Record LLM end time when the response completes
        $this->llmEndTime = microtime(true);

        $usage = $event->payload['usage'] ?? [];
        if (!\is_array($usage)) {
            return;
        }

        // Latest input_tokens (not accumulated) for context window display
        $this->latestInputTokens = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);

        // Accumulated totals for the billing display (running sum across the session)
        $this->inputTokens += $this->latestInputTokens;

        $outputTokens = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $this->outputTokens += $outputTokens;

        // Per-turn output tokens for t/s calculation
        $this->turnOutputTokens += $outputTokens;

        // ── Cache-read tokens (session-level accumulation) ──
        // Cache-read telemetry takes priority; fall back to the aggregate
        // cached_tokens field for runtime events produced by alternate or
        // older event producers that only have aggregate cached-token
        // telemetry and do not split cache-read vs cache-creation.
        $turnCacheRead = $usage['cache_read_tokens'] ?? $usage['cached_tokens'] ?? null;
        if (null !== $turnCacheRead) {
            $this->cacheReadTokens += (int) $turnCacheRead;
            $this->hasCacheTelemetry = true;
        }

        // Cache-creation tokens: only accumulate when explicitly reported
        $turnCacheCreation = $usage['cache_creation_tokens'] ?? null;
        if (null !== $turnCacheCreation) {
            $this->cacheCreationTokens += (int) $turnCacheCreation;
        }

        $cost = $usage['cost'] ?? $usage['total_cost'] ?? null;
        if (\is_float($cost) || \is_int($cost)) {
            $this->totalCost += (float) $cost;
        }
    }

    /**
     * Return the session-level cache-read hit percentage.
     *
     * This is a session-cumulative metric (not per-turn): it divides
     * all accumulated cacheReadTokens by all accumulated inputTokens
     * since the session began.  The result is a running average across
     * every completed turn.
     *
     * Returns null when no cache-read telemetry has been observed
     * this session (the footer hides the cache segment entirely).
     *
     * When telemetry exists but input tokens are zero, returns null
     * to avoid division by zero.  When telemetry exists and input
     * tokens > 0, returns (cacheReadTokens / inputTokens) * 100,
     * capped at 100.
     */
    public function cacheReadHitPercentage(): ?float
    {
        if (!$this->hasCacheTelemetry || $this->inputTokens <= 0) {
            return null;
        }

        $pct = ($this->cacheReadTokens / $this->inputTokens) * 100.0;

        return min(100.0, max(0.0, $pct));
    }
}
