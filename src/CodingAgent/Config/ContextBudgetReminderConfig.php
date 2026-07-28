<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Provider-neutral context-budget wrap-up reminder thresholds.
 *
 * Remaining context is:
 *   context_window - latest_prompt_input_tokens
 *
 * The urgent_remaining_tokens threshold itself is the wrap-up reserve; there is
 * no separate output-headroom subtraction. Early checkpoint uses absolute
 * latest prompt/input usage so cumulative re-counting of prior turns is not a
 * special case — providers report the full current prompt size for each step.
 */
final readonly class ContextBudgetReminderConfig
{
    public const int DEFAULT_EARLY_INPUT_TOKENS = 200000;
    public const int DEFAULT_URGENT_REMAINING_TOKENS = 25000;

    public function __construct(
        #[SerializedName('early_input_tokens')]
        public int $earlyInputTokens = self::DEFAULT_EARLY_INPUT_TOKENS,

        #[SerializedName('urgent_remaining_tokens')]
        public int $urgentRemainingTokens = self::DEFAULT_URGENT_REMAINING_TOKENS,
    ) {
    }

    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->contextBudgetReminders;
    }
}
