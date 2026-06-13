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
     * Reset all per-turn metrics for a new turn.
     *
     * Called by the poller when a TurnStarted event arrives.
     * Session-level fields (inputTokens, outputTokens, totalCost)
     * are NOT reset — they accumulate across the entire session.
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

        $cost = $usage['cost'] ?? $usage['total_cost'] ?? null;
        if (\is_float($cost) || \is_int($cost)) {
            $this->totalCost += (float) $cost;
        }
    }
}
