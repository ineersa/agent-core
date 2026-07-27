<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Provider-neutral context-budget wrap-up reminder thresholds.
 *
 * Usable remaining context is:
 *   context_window - latest_prompt_input_tokens - output_headroom_tokens
 *
 * Early checkpoint uses absolute latest prompt/input usage so cumulative
 * re-counting of prior turns is not a special case — providers report the
 * full current prompt size for each step.
 */
final readonly class ContextBudgetReminderConfig
{
    public const int DEFAULT_EARLY_INPUT_TOKENS = 200000;
    public const int DEFAULT_URGENT_REMAINING_TOKENS = 25000;
    /** Default 0 keeps early meaningful on ~272k windows (urgent would otherwise dominate). */
    public const int DEFAULT_OUTPUT_HEADROOM_TOKENS = 0;

    public function __construct(
        #[SerializedName('early_input_tokens')]
        public int $earlyInputTokens = self::DEFAULT_EARLY_INPUT_TOKENS,

        #[SerializedName('urgent_remaining_tokens')]
        public int $urgentRemainingTokens = self::DEFAULT_URGENT_REMAINING_TOKENS,

        /**
         * Tokens reserved for model output when computing usable remaining context.
         * Documented default is 0 so the early 200k checkpoint remains reachable
         * on 272k-class windows before urgent remaining < 25k fires.
         */
        #[SerializedName('output_headroom_tokens')]
        public int $outputHeadroomTokens = self::DEFAULT_OUTPUT_HEADROOM_TOKENS,
    ) {
    }

    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->contextBudgetReminders;
    }
}
