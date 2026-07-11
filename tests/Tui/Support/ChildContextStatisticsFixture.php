<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

/**
 * Canonical GF-02 child context values for RED specification tests.
 *
 * latest_input_tokens is the latest child LLM step count (not cumulative input_tokens).
 */
final class ChildContextStatisticsFixture
{
    public const string MODEL = 'deepseek/deepseek-v4-flash';

    public const string MODEL_SHORT = 'deepseek-v4-flash';

    public const int CONTEXT_WINDOW = 272_000;

    /** Latest-step input tokens → 36% of {@see CONTEXT_WINDOW}. */
    public const int LATEST_INPUT_TOKENS = 97_920;

    public const string CONTEXT_DETAIL = '36% 97.9k/272.0k';

    public const string TRANSCRIPT_CTX_LINE = 'CTX 36% 97.9k/272.0k';

    /**
     * @return array<string, mixed>
     */
    public static function progressPayloadOverrides(): array
    {
        return self::progressPayloadOverridesWithLatestInput(self::LATEST_INPUT_TOKENS);
    }

    /**
     * @return array<string, mixed>
     */
    public static function progressPayloadOverridesWithLatestInput(
        int $latestInputTokens,
        ?string $model = null,
        ?int $contextWindow = null,
    ): array {
        $payload = [
            'latest_input_tokens' => $latestInputTokens,
            'context_window' => $contextWindow ?? self::CONTEXT_WINDOW,
        ];
        if (null !== $model) {
            $payload['model'] = $model;
        } else {
            $payload['model'] = self::MODEL;
        }

        return $payload;
    }

    /**
     * Progress slice with latest tokens but no canonical context window (graceful degradation).
     *
     * @return array<string, mixed>
     */
    public static function progressPayloadOverridesMissingContextWindow(): array
    {
        return [
            'latest_input_tokens' => self::LATEST_INPUT_TOKENS,
            'model' => self::MODEL,
        ];
    }

    /**
     * Unknown model string with an explicit canonical context window (payload-driven formatting).
     *
     * @return array<string, mixed>
     */
    public static function progressPayloadOverridesUnresolvableModelWithWindow(): array
    {
        return [
            'latest_input_tokens' => self::LATEST_INPUT_TOKENS,
            'model' => 'unknown-provider/no-context-window',
            'context_window' => self::CONTEXT_WINDOW,
        ];
    }

    /**
     * Deepseek provider slice for isolated Hatfield settings (context window resolution).
     *
     * @return array<string, mixed>
     */
    public static function deepseekProviderSettings(): array
    {
        return [
            'type' => 'generic',
            'enabled' => true,
            'api' => 'openai-completions',
            'api_key' => 'dummy',
            'models' => [
                'deepseek-v4-flash' => [
                    'name' => 'DeepSeek V4 Flash',
                    'context_window' => self::CONTEXT_WINDOW,
                    'max_tokens' => self::CONTEXT_WINDOW,
                    'input' => ['text'],
                    'reasoning' => false,
                ],
            ],
        ];
    }
}
