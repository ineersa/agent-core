<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Provider-neutral context-budget wrap-up reminder thresholds.
 *
 * Used by the CodingAgent after-turn hook that queues a user append_message
 * when a committed llm_step_completed crosses a threshold.
 *
 * Remaining context is:
 *   context_window - current_response_input_tokens
 *
 * urgent_remaining_tokens is the sole wrap-up reserve; there is no separate
 * output-headroom subtraction.
 */
final readonly class ContextBudgetReminderConfig
{
    public const int DEFAULT_EARLY_INPUT_TOKENS = 200000;
    public const int DEFAULT_URGENT_REMAINING_TOKENS = 25000;

    public function __construct(
        public int $earlyInputTokens = self::DEFAULT_EARLY_INPUT_TOKENS,

        public int $urgentRemainingTokens = self::DEFAULT_URGENT_REMAINING_TOKENS,
    ) {
    }

    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->contextBudgetReminders;
    }
}
