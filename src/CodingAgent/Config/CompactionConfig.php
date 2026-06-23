<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Compaction settings resolved from Hatfield config.
 *
 * Immutable value object. Controls compaction behaviour: whether
 * auto-compaction is enabled, the flat token threshold for triggering
 * it, the token budget for the retained tail, a global model/thinking-level
 * override for the summarization call, and per-provider/per-model overrides.
 *
 * Hydrated from the compaction section of Hatfield merged config via
 * Symfony Serializer denormalization.
 *
 * @see \Ineersa\CodingAgent\Compaction\SessionCompactor
 */
final readonly class CompactionConfig
{
    public const bool DEFAULT_AUTO_ENABLED = true;
    public const int DEFAULT_COMPACT_AFTER_TOKENS = 120000;
    public const int DEFAULT_KEEP_RECENT_TOKENS = 20000;

    /**
     * @param bool                                $autoEnabled        Controls auto-compaction only; manual /compact is always available
     * @param int                                 $compactAfterTokens Flat token threshold for auto-compaction trigger
     * @param int                                 $keepRecentTokens   Approximate newest tokens to retain raw after compaction
     * @param string|null                         $model              Summarization model override (provider/model format); null = session model fallback
     * @param string|null                         $thinkingLevel      Thinking/reasoning level for summarization calls; null = session default
     * @param array<string, array<string, mixed>> $providerOverrides  Per-provider compaction overrides
     * @param array<string, array<string, mixed>> $modelOverrides     Per-model compaction overrides (wins over provider, which wins over global)
     */
    public function __construct(
        #[SerializedName('auto_enabled')]
        public bool $autoEnabled = self::DEFAULT_AUTO_ENABLED,

        #[SerializedName('compact_after_tokens')]
        public int $compactAfterTokens = self::DEFAULT_COMPACT_AFTER_TOKENS,

        #[SerializedName('keep_recent_tokens')]
        public int $keepRecentTokens = self::DEFAULT_KEEP_RECENT_TOKENS,

        /**
         * Summarization model override.
         *
         * When null, the active session model is used for summarization.
         * When non-null, the value must be a `provider/model` string
         * (e.g. `llama_cpp/flash`).
         */
        public ?string $model = null,

        /**
         * Thinking/reasoning level for the summarization call.
         *
         * When null, the session's active thinking level is used.
         * Typical values: off, minimal, low, medium, high, xhigh.
         */
        #[SerializedName('thinking_level')]
        public ?string $thinkingLevel = null,

        /**
         * Per-provider compaction override settings.
         *
         * Keys are provider IDs (e.g. 'openai', 'llama_cpp').
         * Each value is a map with optional keys: compact_after_tokens,
         * model, thinking_level.
         *
         * @var array<string, array<string, mixed>>
         */
        #[SerializedName('provider_overrides')]
        public array $providerOverrides = [],

        /**
         * Per-model compaction override settings.
         *
         * Keys are `provider/model` strings (e.g. 'openai/gpt-4.1').
         * Each value is a map with optional keys: compact_after_tokens,
         * model, thinking_level. Model overrides win over provider overrides,
         * which win over global settings.
         *
         * @var array<string, array<string, mixed>>
         */
        #[SerializedName('model_overrides')]
        public array $modelOverrides = [],
    ) {
    }

    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->compaction;
    }

    /**
     * Parse the global model override into a provider/model reference.
     *
     * Returns null when model is null (session model fallback).
     * Throws \InvalidArgumentException when the string is not a valid
     * provider/model reference.
     */
    public function resolveModelReference(): ?AiModelReference
    {
        if (null === $this->model) {
            return null;
        }

        return AiModelReference::parse($this->model);
    }

    /**
     * Resolve effective compaction runtime settings for a specific active
     * model, applying provider and model overrides on top of global defaults.
     *
     * Override precedence: model > provider > global.
     *
     * @param string|null $activeModel Active session model in provider/model format (e.g. 'openai/gpt-4.1')
     */
    public function resolveRuntimeSettings(?string $activeModel): CompactionRuntimeSettingsDTO
    {
        $compactAfterTokens = $this->compactAfterTokens;
        $model = $this->model;
        $thinkingLevel = $this->thinkingLevel;

        if (null !== $activeModel) {
            $providerId = $this->extractProviderId($activeModel);

            // Provider override.
            if (isset($this->providerOverrides[$providerId])) {
                $prov = $this->providerOverrides[$providerId];
                $compactAfterTokens = (int) ($prov['compact_after_tokens'] ?? $compactAfterTokens);
                $model = \is_string($prov['model'] ?? null) ? $prov['model'] : $model;
                $thinkingLevel = \is_string($prov['thinking_level'] ?? null) ? $prov['thinking_level'] : $thinkingLevel;
            }

            // Model override (wins over provider).
            if (isset($this->modelOverrides[$activeModel])) {
                $mod = $this->modelOverrides[$activeModel];
                $compactAfterTokens = (int) ($mod['compact_after_tokens'] ?? $compactAfterTokens);
                $model = \is_string($mod['model'] ?? null) ? $mod['model'] : $model;
                $thinkingLevel = \is_string($mod['thinking_level'] ?? null) ? $mod['thinking_level'] : $thinkingLevel;
            }
        }

        return new CompactionRuntimeSettingsDTO(
            autoEnabled: $this->autoEnabled,
            compactAfterTokens: $compactAfterTokens,
            keepRecentTokens: $this->keepRecentTokens,
            model: $model,
            thinkingLevel: $thinkingLevel,
        );
    }

    /**
     * Extract the provider ID from a provider/model string.
     *
     * Only the provider/model shape (e.g. 'openai/gpt-4.1') is
     * recognized. Bare strings without '/' (e.g. 'gpt-4.1') return
     * an empty string so they never accidentally match provider
     * override keys.
     */
    private function extractProviderId(string $modelRef): string
    {
        $parts = explode('/', $modelRef, 2);

        if (\count($parts) < 2) {
            return '';
        }

        return $parts[0];
    }
}
